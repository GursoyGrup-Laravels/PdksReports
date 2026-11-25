<?php

namespace Tests\Feature;

use App\Enums\ManagerStatusEnum;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Report;
use App\Models\Scopes\AuthorizedReportScope;
use App\Models\Staff;
use App\Models\User;
use App\Services\EmployeeService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmpCodeSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_people_without_tc_stay_separate_and_keep_leading_zeros(): void
    {
        $this->syncEmployees([$this->person('42', ''), $this->person('0042', null), $this->person('7', '', null, null)]);

        $this->assertSame(['0042', '42', '7'], Employee::orderBy('emp_code')->pluck('emp_code')->all());
        $this->assertSame('7', Employee::firstWhere('emp_code', '7')->full_name);

        $this->syncReports([$this->row('42', ''), $this->row('0042', null)]);

        $this->assertSame('42', Report::firstWhere('external_id', '42_20261125')->employee->emp_code);
        $this->assertSame('0042', Report::firstWhere('external_id', '0042_20261125')->employee->emp_code);
    }

    public function test_existing_employee_is_adopted_and_sync_is_idempotent(): void
    {
        $existing = $this->employee('11111111110');

        $this->syncEmployees([$this->person('1016', '11111111110')]);
        $this->syncEmployees([$this->person('1016', '')]);

        $this->assertSame(1, Employee::count());
        $this->assertSame('1016', $existing->refresh()->emp_code);
        $this->assertSame('11111111110', $existing->tc_no);
    }

    public function test_shared_tc_is_never_guessed_until_linked_manually(): void
    {
        $existing = $this->employee('11111111110');
        $people = [$this->person('1', '11111111110'), $this->person('2', '11111111110')];

        $this->syncEmployees($people);
        $this->assertNull($existing->refresh()->emp_code);
        $this->assertSame(1, Employee::count());

        $this->syncReports([$this->row('1', '11111111110'), $this->row('2', '11111111110')]);
        $this->assertSame(2, Report::where('employee_id', $existing->id)->count());

        $this->artisan('employee:link-code', ['employee' => $existing->id, 'emp_code' => '1'])->assertSuccessful();
        $this->syncEmployees($people);

        $second = Employee::firstWhere('emp_code', '2');
        $this->assertSame($existing->id, Report::firstWhere('external_id', '1_20261125')->employee_id);
        $this->assertSame($second->id, Report::firstWhere('external_id', '2_20261125')->employee_id);
        $this->artisan('employee:link-code', ['employee' => $second->id, 'emp_code' => '1'])->assertFailed();
    }

    public function test_relinking_does_not_touch_similar_codes(): void
    {
        $old = $this->employee('11111111110');
        foreach (['1_20260101', '11_20260101', '1_2_20260101'] as $externalId) {
            DB::table('reports')->insert(['employee_id' => $old->id, 'external_id' => $externalId, 'status' => 0]);
        }

        $this->syncEmployees([$this->person('1', '')]);

        $new = Employee::firstWhere('emp_code', '1');
        $this->assertSame(['1_20260101'], Report::where('employee_id', $new->id)->pluck('external_id')->all());
    }

    public function test_bad_rows_do_not_stop_the_sync(): void
    {
        $this->syncEmployees([
            $this->person('1', '1234567890123'),
            $this->person('', '11111111110'),
            $this->person(str_repeat('9', 60), ''),
            $this->person('2', '', 'Ali', 'Can', 7),
        ]);

        $this->assertNull(Employee::firstWhere('emp_code', '1')->tc_no);
        $this->assertSame(ManagerStatusEnum::UNDEFINED, Employee::firstWhere('emp_code', '2')->status);
        $this->assertSame(2, Employee::count());

        $this->syncReports([$this->row('yok', ''), ['external_id' => 'bozuk'] + $this->row('1', ''), $this->row('2', '', 7)]);

        $this->assertSame(['2_20261125'], Report::pluck('external_id')->all());
        $this->assertSame(ManagerStatusEnum::UNDEFINED, Report::first()->status);
    }

    public function test_manager_sees_only_own_staff_when_tc_is_empty(): void
    {
        $this->syncEmployees([$this->person('1', ''), $this->person('2', ''), $this->person('3', '')]);
        $this->syncReports([$this->row('1', ''), $this->row('2', ''), $this->row('3', '')]);
        [$boss, $mine] = [Employee::firstWhere('emp_code', '1'), Employee::firstWhere('emp_code', '2')];

        $manager = Manager::create(['employee_id' => $boss->id, 'status' => 0, 'created_by' => 1]);
        $staff = Staff::create(['manager_id' => $manager->id, 'employee_id' => $mine->id, 'created_by' => 1]);
        $user = User::create(['employee_id' => $boss->id, 'name' => 'B', 'email' => 'b@example.com', 'password' => 'x', 'status' => 0, 'created_by' => 1]);

        $seen = AuthorizedReportScope::apply(Report::query(), $user)->orderBy('external_id')->pluck('external_id')->all();

        $this->assertSame(['1_20261125', '2_20261125'], $seen);
        $this->assertSame(['2_20261125'], $staff->reports()->pluck('reports.external_id')->all());
    }

    public function test_recent_sync_merges_terminals_and_never_shrinks_readings(): void
    {
        $this->travelTo('2026-11-25 03:30:00');
        $this->syncEmployees([$this->person('1', ''), $this->person('2', '')]);
        $base = ['employee_id' => Employee::firstWhere('emp_code', '1')->id, 'department_name' => 'Eski', 'status' => 0];
        DB::table('reports')->insert($base + ['external_id' => '1_20261122', 'date' => '2026-11-22']);
        DB::table('reports')->insert($base + ['external_id' => '1_20261123', 'date' => '2026-11-23', 'first_reading' => '2026-11-23 08:00:00', 'last_reading' => '2026-11-23 19:00:00']);
        DB::table('reports')->insert($base + ['external_id' => '2_20261124', 'date' => '2026-11-24']);

        $this->fake('cardreadingsninedays', [
            $this->terminalRow('1', '21', '10:00:00', '11:00:00'),
            $this->terminalRow('1', '22', '13:00:00', '18:05:10', 100),
            $this->terminalRow('1', '22', '09:00:00', '12:00:00', 100),
            $this->terminalRow('1', '23', '09:00:00', '18:00:00'),
            $this->terminalRow('2', '23', '09:30:00', '09:30:00'),
            $this->terminalRow('2', '25', '01:00:00', '01:00:00'),
            $this->terminalRow('yok', '23', '09:00:00', '10:00:00'),
        ]);
        $this->artisan('reports:sync-recent')->assertSuccessful();

        $filled = Report::firstWhere('external_id', '1_20261122');
        $this->assertSame(['2026-11-22 09:00:00', '2026-11-22 18:05:10', '09:05:10'], [$filled->first_reading, $filled->last_reading, $filled->working_time]);
        $this->assertSame(['Eski', ManagerStatusEnum::ACTIVE], [$filled->department_name, $filled->status]);

        $kept = Report::firstWhere('external_id', '1_20261123');
        $this->assertSame(['2026-11-23 08:00:00', '2026-11-23 19:00:00'], [$kept->first_reading, $kept->last_reading]);
        $this->assertNull(Report::firstWhere('external_id', '2_20261124')->first_reading);
        $this->assertSame(['1_20261122', '1_20261123', '2_20261123', '2_20261124'], Report::orderBy('external_id')->pluck('external_id')->all());
    }

    public function test_recent_sync_fails_without_writing_on_empty_or_error_response(): void
    {
        $this->fake('cardreadingsninedays', []);
        $this->artisan('reports:sync-recent')->assertFailed();

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*' => Http::response('x', 500)]);
        $this->artisan('reports:sync-recent')->assertFailed();
        $this->assertSame(0, Report::count());
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalRow(string $empCode, string $day, string $first, string $last, int $status = 0): array
    {
        return [
            'external_id' => "{$empCode}_202611{$day}",
            'date' => "2026-11-{$day}",
            'department_name' => 'Yeni',
            'first_reading' => "{$day} Nov 2026 {$first} GMT",
            'last_reading' => "{$day} Nov 2026 {$last} GMT",
        ] + $this->row($empCode, '', $status);
    }

    private function employee(string $tcNo): Employee
    {
        return Employee::create(['tc_no' => $tcNo, 'first_name' => 'Test', 'last_name' => 'Kisi', 'status' => 0]);
    }

    /**
     * @return array<string, mixed>
     */
    private function person(string $empCode, ?string $tcNo, ?string $first = 'Test', ?string $last = 'Kisi', int $status = 0): array
    {
        return ['emp_code' => $empCode, 'nickname' => $tcNo, 'first_name' => $first, 'last_name' => $last, 'status' => $status];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $empCode, ?string $tcNo, int $status = 0): array
    {
        return ['external_id' => "{$empCode}_20261125", 'tc_no' => $tcNo, 'full_name' => 'Test Kisi', 'date' => '2026-11-25', 'status' => $status];
    }

    private function syncEmployees(array $rows): void
    {
        $this->fake('personnel_employee', $rows);
        app(EmployeeService::class)->employeeService();
    }

    private function syncReports(array $rows): void
    {
        $this->fake('cardreadingsdaily', $rows);
        app(ReportService::class)->reportsDailyService();
    }

    private function fake(string $endpoint, array $rows): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(["*/api/zk/{$endpoint}" => Http::response($rows)]);
    }
}
