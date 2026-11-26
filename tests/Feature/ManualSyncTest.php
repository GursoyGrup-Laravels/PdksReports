<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Jobs\RunSyncJob;
use App\Models\Employee;
use App\Models\Report;
use App\Models\User;
use App\Services\SyncLock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ManualSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->travelTo('2026-11-26 12:00:00');
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));

        foreach (['view_any_report', 'view_any_employee', 'view_all_reports', 'view_all_employees', 'sync_employees', 'sync_reports', 'import_reports'] as $name) {
            Permission::findOrCreate($name);
        }

        Role::create(['name' => 'super_admin']);
        Role::create(['name' => 'senkron'])->givePermissionTo(Permission::all());
        Role::create(['name' => 'izlemeci'])->givePermissionTo(['view_any_report', 'view_any_employee', 'view_all_reports', 'view_all_employees']);

        $this->user = User::create(['employee_id' => 0, 'name' => 'S', 'email' => 's@example.com', 'password' => 'x', 'status' => 0, 'created_by' => 1]);
        $this->user->assignRole('senkron');
    }

    public function test_super_admin_role_receives_the_new_permissions(): void
    {
        $migration = require database_path('migrations/2026_11_26_000001_add_sync_permissions.php');
        $migration->up();

        $this->assertTrue(Role::findByName('super_admin')->hasPermissionTo('import_reports'));
    }

    public function test_buttons_are_visible_only_with_permission(): void
    {
        $viewer = User::create(['employee_id' => 0, 'name' => 'V', 'email' => 'v@example.com', 'password' => 'x', 'status' => 0, 'created_by' => 1]);
        $viewer->assignRole('izlemeci');

        $this->actingAs($viewer);
        Livewire::test(ListReports::class)->assertActionHidden('syncReports')->assertActionHidden('importReports');
        Livewire::test(ListEmployees::class)->assertActionHidden('syncEmployees');

        $this->actingAs($this->user);
        Livewire::test(ListReports::class)->assertActionVisible('syncReports')->assertActionVisible('importReports')->assertActionHidden('confirmImport');
        Livewire::test(ListEmployees::class)->assertActionVisible('syncEmployees');
    }

    public function test_second_click_is_blocked_while_running_and_scheduled_runs_skip(): void
    {
        Queue::fake();
        $this->actingAs($this->user);

        Livewire::test(ListEmployees::class)->callAction('syncEmployees');
        Queue::assertPushed(RunSyncJob::class, 1);
        $this->assertTrue(SyncLock::isRunning(SyncLock::EMPLOYEES));

        Livewire::test(ListEmployees::class)->assertActionDisabled('syncEmployees')->callAction('syncEmployees');
        Queue::assertPushed(RunSyncJob::class, 1);

        $this->artisan('employee:daily')->expectsOutputToContain('atlandı')->assertSuccessful();
        Livewire::test(ListReports::class)->assertActionEnabled('syncReports');
    }

    public function test_job_runs_and_releases_the_lock_even_when_it_fails(): void
    {
        $this->actingAs($this->user);
        Http::fake(['*personnel_employee' => Http::response([['emp_code' => '7', 'nickname' => '', 'first_name' => 'A', 'last_name' => 'B', 'status' => 0]]), '*cardreadingsdaily' => Http::response([])]);

        Livewire::test(ListEmployees::class)->callAction('syncEmployees');
        $this->assertSame(1, Employee::count());
        $this->assertFalse(SyncLock::isRunning(SyncLock::EMPLOYEES));
        $this->assertStringContainsString('1', $this->user->notifications()->latest()->first()->data['body']);

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*' => Http::response('hata', 500)]);
        Livewire::test(ListReports::class)->callAction('syncReports');
        $this->assertFalse(SyncLock::isRunning(SyncLock::REPORTS));
        $this->assertContains(__('ui.sync_failed'), $this->user->notifications()->get()->pluck('data.title'));
        $this->assertSame(0, Report::count());
    }

    public function test_import_previews_split_zip_files_then_writes_after_confirmation(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user);
        $employee = Employee::create(['emp_code' => '2623', 'first_name' => 'Y', 'last_name' => 'B', 'status' => 0]);
        $line = fn (string $day, ?string $at = null): string => implode("\t", ["2623_202611{$day}", '', 'Y B', 'D', 'NULL', "2026-11-{$day}", 'G', $at ? "2026-11-{$day} {$at}.000" : 'NULL', $at ? "2026-11-{$day} 18:00:00.000" : 'NULL', 'NULL', '0']);
        $zip = function (string $name, string $content): UploadedFile {
            $path = tempnam(sys_get_temp_dir(), 'z');
            $archive = new \ZipArchive;
            $archive->open($path, \ZipArchive::OVERWRITE);
            $archive->addFromString("{$name}.txt", "\xEF\xBB\xBF".$content);
            $archive->close();

            return UploadedFile::fake()->createWithContent("{$name}.zip", (string) file_get_contents($path));
        };

        Livewire::test(ListReports::class)
            ->callAction('importReports', ['files' => [$zip('a', $line('01', '09:00:00')), $zip('b', $line('02'))]])
            ->assertHasNoActionErrors();

        $this->assertSame(0, Report::count());
        $this->assertSame(2, Cache::get(RunSyncJob::previewKey($this->user->id))['stats']['eklenen']);
        Livewire::test(ListReports::class)->assertActionVisible('confirmImport')->assertActionHidden('importReports')->callAction('confirmImport');

        $this->assertSame(2, Report::where('employee_id', $employee->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles('report-imports'));
        $this->assertFalse(Cache::has(RunSyncJob::previewKey($this->user->id)));
        $this->assertFalse(SyncLock::isRunning(SyncLock::REPORTS));
    }

    public function test_bad_import_files_are_deleted_and_nothing_is_written(): void
    {
        Storage::fake('local');
        $this->actingAs($this->user);

        Livewire::test(ListReports::class)->callAction('importReports', ['files' => [UploadedFile::fake()->createWithContent('x.txt', "bozuk\tsatir")]]);

        $this->assertSame([], Storage::disk('local')->allFiles('report-imports'));
        $this->assertFalse(Cache::has(RunSyncJob::previewKey($this->user->id)));
        $this->assertSame(__('ui.sync_failed'), $this->user->notifications()->first()->data['title']);
        $this->assertFalse(SyncLock::isRunning(SyncLock::REPORTS));
    }

    public function test_tc_comes_from_the_employee_so_history_shows_a_later_added_tc(): void
    {
        $employee = Employee::create(['emp_code' => '2623', 'first_name' => 'Y', 'last_name' => 'B', 'status' => 0]);
        DB::table('reports')->insert(['employee_id' => $employee->id, 'external_id' => '2623_20261101', 'date' => '2026-11-01', 'tc_no' => null, 'status' => 0]);
        $employee->update(['tc_no' => '12345678901']);
        $this->actingAs($this->user);

        $page = Livewire::test(ListReports::class)->set('tableFilters.today.isActive', false);
        $column = $page->instance()->getTable()->getColumn('employee.tc_no')->record(Report::first());
        $this->assertSame('12345678901', $column->getState());
        $page->set('tableSearch', '12345678901')->assertCanNotSeeTableRecords(Report::all());

        Permission::findOrCreate('view_tc_no');
        $this->user->givePermissionTo('view_tc_no');
        $this->actingAs($this->user->fresh());
        $page = Livewire::test(ListReports::class)->set('tableFilters.today.isActive', false);
        $page->set('tableSearch', '12345678901')->assertCanSeeTableRecords(Report::all());
        $page->set('tableSearch', '99999999999')->assertCanNotSeeTableRecords(Report::all());

        $columns = collect(\App\Filament\Exports\ReportExporter::getColumns())->map->getName();
        $this->assertContains('employee.tc_no', $columns);
    }
}
