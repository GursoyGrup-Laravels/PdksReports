<?php

namespace Tests\Feature;

use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\ManagerResource;
use App\Filament\Resources\ReportResource;
use App\Filament\Resources\StaffResource;
use App\Filament\Resources\UserResource;
use App\Filament\Widgets\ReportsOverview;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_badges_show_today_and_active_counts_within_permission(): void
    {
        $this->travelTo('2026-11-25 12:00:00');
        [$boss, $mine, $passive, $other] = [$this->employee('1'), $this->employee('2'), $this->employee('3', 100), $this->employee('4')];
        $this->report($boss, '2026-11-25', true);
        $this->report($mine, '2026-11-25', false);
        $this->report($passive, '2026-11-25', true);
        $this->report($other, '2026-11-25', true);
        $this->report($mine, '2026-11-24', true);

        $manager = Manager::create(['employee_id' => $boss->id, 'status' => 0, 'created_by' => 1]);
        $second = Manager::create(['employee_id' => $other->id, 'status' => 100, 'created_by' => 1]);
        foreach ([[$manager, $mine], [$manager, $passive], [$second, $mine]] as [$owner, $employee]) {
            Staff::create(['manager_id' => $owner->id, 'employee_id' => $employee->id, 'created_by' => 1]);
        }

        $admin = $this->user(0, 'a@example.com');
        $admin->assignRole(Role::create(['name' => 'super_admin']));

        $this->actingAs($admin);
        $this->assertSame('2 / 3', ReportResource::getNavigationBadge());
        $this->assertSame(['3', '1', '1', null], [EmployeeResource::getNavigationBadge(), StaffResource::getNavigationBadge(), ManagerResource::getNavigationBadge(), UserResource::getNavigationBadge()]);
        $this->assertStringContainsString('4', EmployeeResource::getNavigationBadgeTooltip());
        Livewire::test(ReportsOverview::class)->assertSee('67')->assertSee(__('ui.expected_today'));

        $this->actingAs($this->user($boss->id, 'b@example.com'));
        $this->assertSame('1 / 2', ReportResource::getNavigationBadge());

        $this->actingAs($this->user(0, 'c@example.com'));
        $this->assertNull(ReportResource::getNavigationBadge());
        Livewire::test(ReportsOverview::class)->assertOk();
    }

    private function employee(string $empCode, int $status = 0): Employee
    {
        return Employee::create(['emp_code' => $empCode, 'first_name' => 'Test', 'last_name' => 'Kisi', 'status' => $status]);
    }

    private function user(int $employeeId, string $email): User
    {
        return User::create(['employee_id' => $employeeId, 'name' => 'T', 'email' => $email, 'password' => 'x', 'status' => 0, 'created_by' => 1]);
    }

    private function report(Employee $employee, string $date, bool $read): void
    {
        DB::table('reports')->insert([
            'employee_id' => $employee->id,
            'external_id' => $employee->emp_code . '_' . str_replace('-', '', $date),
            'date' => $date,
            'status' => $employee->status->value,
            'first_reading' => $read ? "{$date} 09:00:00" : null,
            'last_reading' => $read ? "{$date} 18:00:00" : null,
        ]);
    }
}
