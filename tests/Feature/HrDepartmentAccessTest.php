<?php

namespace Tests\Feature;

use App\Enums\ManagerTypeEnum;
use App\Filament\Resources\EmployeeResource;
use App\Filament\Resources\EmployeeResource\Pages\ListEmployees;
use App\Filament\Resources\ManagerResource;
use App\Filament\Resources\ManagerResource\Pages\CreateManager;
use App\Filament\Resources\ManagerResource\Pages\EditManager;
use App\Filament\Resources\ManagerResource\Pages\ListManagers;
use App\Filament\Resources\ManagerResource\Pages\ViewManager;
use App\Filament\Resources\ManagerResource\RelationManagers\StaffsRelationManager;
use App\Filament\Resources\ReportResource\Pages\ListReports;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Widgets\LatestReports;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Report;
use App\Models\Scopes\AuthorizedEmployeeScope;
use App\Models\Scopes\AuthorizedReportScope;
use App\Models\Staff;
use App\Models\User;
use App\Services\DepartmentService;
use App\Services\EmployeeService;
use App\Services\ReportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HrDepartmentAccessTest extends TestCase
{
    use RefreshDatabase;

    private Department $a;

    private Department $b;

    /** @var array<string, Employee> */
    private array $people = [];

    private User $hr;

    private User $admin;

    private Manager $hrRecord;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
        $this->travelTo('2026-11-25 12:00:00');
        Filament::setCurrentPanel(Filament::getPanel('dashboard'));

        $this->a = Department::create(['source_id' => 10, 'name' => 'A']);
        $this->b = Department::create(['source_id' => 20, 'name' => 'B']);
        $merkez = Department::create(['source_id' => 30, 'name' => 'MERKEZ']);

        foreach (['hr' => $merkez, 'colleague' => $merkez, 'a1' => $this->a, 'aPassive' => $this->a, 'b1' => $this->b, 'b2' => $this->b] as $key => $department) {
            $this->people[$key] = Employee::create(['emp_code' => $key, 'department_id' => $department->id, 'first_name' => $key, 'last_name' => 'X', 'status' => $key === 'aPassive' ? 100 : 0]);
            $this->report($key, '2026-11-25', $department);
        }

        foreach (['view_any_report', 'view_report', 'view_any_employee', 'view_any_manager', 'view_manager', 'create_manager', 'update_manager', 'view_any_staff', 'create_staff', 'update_staff', 'view_any_user', 'view_user', 'create_user', 'update_user', 'view_any_role', 'update_role', 'view_tc_no'] as $name) {
            Permission::findOrCreate($name);
        }

        Role::create(['name' => 'super_admin'])->givePermissionTo(Permission::all());
        Role::create(['name' => 'IK'])->givePermissionTo(Permission::where('name', '!=', 'view_tc_no')->get());
        Role::create(['name' => 'basit'])->givePermissionTo('view_any_report');
        Role::create(['name' => 'tc'])->givePermissionTo('view_tc_no');

        $this->admin = $this->user(0, 'admin@example.com', 'super_admin');
        $this->hr = $this->user($this->people['hr']->id, 'hr@example.com', 'IK');
        $this->hrRecord = Manager::create(['employee_id' => $this->people['hr']->id, 'type' => ManagerTypeEnum::HR, 'status' => 0, 'created_by' => 1]);
        $this->hrRecord->departments()->sync([$this->a->id]);
    }

    public function test_hr_sees_selected_departments_and_own_card_only(): void
    {
        $this->assertSame(['a1', 'aPassive', 'hr'], $this->visibleReports($this->hr));
        $this->assertSame(['a1', 'aPassive'], $this->visibleEmployees($this->hr));

        $this->hrRecord->departments()->sync([$this->b->id]);
        $this->assertSame(['b1', 'b2', 'hr'], $this->visibleReports($this->hr));

        $this->hrRecord->departments()->sync([]);
        $this->assertSame(['hr'], $this->visibleReports($this->hr));
        $this->assertSame([], $this->visibleEmployees($this->hr));
    }

    public function test_passive_or_manager_type_records_give_no_department_access(): void
    {
        $this->hrRecord->update(['type' => ManagerTypeEnum::MANAGER]);
        $this->assertSame(['hr'], $this->visibleReports($this->hr));

        $this->hrRecord->update(['type' => ManagerTypeEnum::HR, 'status' => 100]);
        Staff::create(['manager_id' => $this->hrRecord->id, 'employee_id' => $this->people['b1']->id, 'created_by' => 1]);
        $this->assertSame(['hr'], $this->visibleReports($this->hr));
    }

    public function test_hr_can_also_have_individually_assigned_staff(): void
    {
        Staff::create(['manager_id' => $this->hrRecord->id, 'employee_id' => $this->people['b1']->id, 'created_by' => 1]);
        $this->assertSame(['a1', 'aPassive', 'b1', 'hr'], $this->visibleReports($this->hr));
    }

    public function test_transferred_person_history_stays_with_the_department_of_that_day(): void
    {
        $this->report('b1', '2026-11-10', $this->a);
        $this->report('a1', '2026-11-10', $this->b);
        $seen = AuthorizedReportScope::apply(Report::query(), $this->hr->fresh())->where('date', '2026-11-10')->pluck('external_id')->all();

        $this->assertSame(['b1_20261110'], $seen);
        $this->assertNotContains('b1', $this->visibleEmployees($this->hr));
    }

    public function test_manager_access_is_unchanged(): void
    {
        $manager = Manager::create(['employee_id' => $this->people['b2']->id, 'status' => 0, 'created_by' => 1]);
        foreach (['b1', 'aPassive'] as $key) {
            Staff::create(['manager_id' => $manager->id, 'employee_id' => $this->people[$key]->id, 'created_by' => 1]);
        }
        $user = $this->user($this->people['b2']->id, 'm@example.com', 'basit');

        $this->assertSame(['b1', 'b2'], $this->visibleReports($user));
        $this->assertSame(['b1'], $this->visibleEmployees($user));
    }

    public function test_screens_and_filters_do_not_leak_other_departments(): void
    {
        $this->actingAs($this->hr->fresh());

        $reports = Livewire::test(ListReports::class)->set('tableFilters.status.value', null);
        $this->assertSame(3, $reports->instance()->getTableRecords()->total());
        $this->assertSame(['A', 'MERKEZ'], array_values($reports->instance()->getTable()->getFilter('department_name')->getOptions()));
        Livewire::test(LatestReports::class)->assertDontSee('b1 X')->assertDontSee('colleague X');
        Livewire::test(ListEmployees::class)->assertSee('a1')->assertDontSee('b1')->assertDontSee('colleague');
        $this->assertSame('1', EmployeeResource::getNavigationBadge());

        $options = Livewire::test(CreateUser::class)->instance()->form->getFlatFields()['employee_id']->getOptions();
        $this->assertSame([$this->people['a1']->id], array_keys($options));
    }

    public function test_hr_manages_only_managers_and_staff_of_selected_departments(): void
    {
        $inside = Manager::create(['employee_id' => $this->people['a1']->id, 'status' => 0, 'created_by' => 1]);
        $outside = Manager::create(['employee_id' => $this->people['b2']->id, 'status' => 0, 'created_by' => 1]);
        $hidden = Staff::create(['manager_id' => $inside->id, 'employee_id' => $this->people['b1']->id, 'created_by' => 1]);
        $this->user($this->people['a1']->id, 'a1@example.com', 'basit');
        $this->actingAs($this->hr->fresh());

        Livewire::test(ListManagers::class)->assertCanSeeTableRecords([$inside])->assertCanNotSeeTableRecords([$outside, $this->hrRecord]);
        $this->assertFalse(ManagerResource::canEdit($this->hrRecord));
        Livewire::test(EditManager::class, ['record' => $inside->getKey()])->assertFormFieldIsHidden('type')->assertOk();

        $staffs = fn () => Livewire::test(StaffsRelationManager::class, ['ownerRecord' => $inside, 'pageClass' => ViewManager::class]);
        $staffs()->assertCanNotSeeTableRecords([$hidden]);
        try {
            $staffs()->callTableAction('create', data: ['employee_id' => $this->people['colleague']->id]);
        } catch (\ErrorException) {
            // 403 yanıtında Filament test yardımcısı bileşeni bulamaz
        }

        $this->assertSame(0, Staff::where('employee_id', $this->people['colleague']->id)->count());

        $this->people['a2'] = Employee::create(['emp_code' => 'a2', 'department_id' => $this->a->id, 'first_name' => 'a2', 'last_name' => 'X', 'status' => 0]);
        $staffs()->callTableAction('create', data: ['employee_id' => $this->people['a2']->id])->assertHasNoTableActionErrors();
        $this->assertSame($inside->id, Staff::firstWhere('employee_id', $this->people['a2']->id)->manager_id);
    }

    public function test_hr_cannot_create_a_manager_outside_or_an_hr_record(): void
    {
        $this->user($this->people['b1']->id, 'b1@example.com', 'basit', $this->hr->id);
        $this->user($this->people['a1']->id, 'a1@example.com', 'basit', $this->hr->id);
        $this->actingAs($this->hr->fresh());

        Livewire::test(CreateManager::class)->set('data.employee_id', $this->people['b1']->id)->call('create');
        $this->assertSame(0, Manager::where('employee_id', $this->people['b1']->id)->count());

        Livewire::test(CreateManager::class)->set('data.employee_id', $this->people['a1']->id)->set('data.type', 1)->set('data.departments', [$this->b->id])->call('create');
        $created = Manager::firstWhere('employee_id', $this->people['a1']->id);
        $this->assertSame(ManagerTypeEnum::MANAGER, $created->type);
        $this->assertSame(0, $created->departments()->count());
    }

    public function test_hr_cannot_escalate_roles_or_touch_stronger_users(): void
    {
        $this->actingAs($this->hr->fresh());

        foreach (['super_admin', 'tc'] as $role) {
            Livewire::test(CreateUser::class)->set('data.employee_id', $this->people['a1']->id)->set('data.email', 'n@example.com')->set('data.roles', [Role::findByName($role)->id])->call('create');
            $this->assertSame(0, User::where('email', 'n@example.com')->count());
        }

        Livewire::test(CreateUser::class)->set('data.employee_id', $this->people['b1']->id)->set('data.email', 'o@example.com')->set('data.roles', [Role::findByName('basit')->id])->call('create');
        $this->assertSame(0, User::where('email', 'o@example.com')->count());

        Livewire::test(CreateUser::class)->set('data.employee_id', $this->people['a1']->id)->set('data.email', 'ok@example.com')->set('data.roles', [Role::findByName('basit')->id])->call('create')->assertHasNoFormErrors();
        $this->assertTrue(User::firstWhere('email', 'ok@example.com')->hasRole('basit'));

        $strong = $this->user($this->people['colleague']->id, 's@example.com', 'super_admin', $this->hr->id);
        $this->assertFalse(UserResource::canEdit($strong));
        $this->assertFalse(UserResource::canDelete($strong));
        $this->assertTrue(UserResource::canEdit(User::firstWhere('email', 'ok@example.com')));
        $this->assertFalse($this->hr->fresh()->can('update', Role::findByName('IK')));
        $this->assertTrue($this->admin->fresh()->can('update', Role::findByName('IK')));
    }

    public function test_records_created_while_super_admin_do_not_stay_visible_after_demotion(): void
    {
        $insideUser = $this->user($this->people['a1']->id, 'in@example.com', 'basit', $this->hr->id);
        $outsideUser = $this->user($this->people['b1']->id, 'out@example.com', 'basit', $this->hr->id);
        $inside = Manager::create(['employee_id' => $this->people['a1']->id, 'status' => 0, 'created_by' => $this->hr->id]);
        $outside = Manager::create(['employee_id' => $this->people['b1']->id, 'status' => 0, 'created_by' => $this->hr->id]);

        $this->actingAs($this->hr->fresh());
        Livewire::test(ListManagers::class)->assertCanSeeTableRecords([$inside])->assertCanNotSeeTableRecords([$outside]);
        Livewire::test(ListUsers::class)->assertCanSeeTableRecords([$insideUser])->assertCanNotSeeTableRecords([$outsideUser]);
        $this->assertSame('1', ManagerResource::getNavigationBadge());

        $this->hrRecord->update(['status' => 100]);
        $this->actingAs($this->hr->fresh());
        Livewire::test(ListManagers::class)->assertCanNotSeeTableRecords([$inside, $outside]);
        Livewire::test(ListUsers::class)->assertCanNotSeeTableRecords([$insideUser, $outsideUser]);
    }

    public function test_admin_defines_hr_with_departments_from_the_panel(): void
    {
        $this->user($this->people['colleague']->id, 'c@example.com', 'IK');
        $this->actingAs($this->admin->fresh());

        Livewire::test(CreateManager::class)
            ->set('data.employee_id', $this->people['colleague']->id)
            ->assertFormFieldIsHidden('departments')
            ->set('data.type', ManagerTypeEnum::HR->value)
            ->assertFormFieldIsVisible('departments')
            ->set('data.departments', [$this->b->id])
            ->call('create')->assertHasNoFormErrors();

        $record = Manager::firstWhere('employee_id', $this->people['colleague']->id);
        $this->assertSame([ManagerTypeEnum::HR, [$this->b->id]], [$record->type, $record->departments()->pluck('departments.id')->all()]);

        Livewire::test(EditManager::class, ['record' => $record->getKey()])
            ->assertFormFieldIsVisible('departments')
            ->set('data.departments', [$this->a->id, $this->b->id])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame(2, $record->departments()->count());
        Livewire::test(ListManagers::class)->assertCanSeeTableRecords([$record, $this->hrRecord]);
    }

    public function test_sync_learns_departments_and_unknown_names_stay_hidden(): void
    {
        $people = fn (int $department): array => [['emp_code' => 'n1', 'nickname' => '', 'first_name' => 'N', 'last_name' => 'X', 'status' => 0, 'department_id' => $department]];
        $row = fn (string $name): array => [['external_id' => 'n1_20261125', 'tc_no' => '', 'full_name' => 'N X', 'department_name' => $name, 'date' => '2026-11-25', 'status' => 0]];
        $sync = function (array $employees, array $rows): void {
            Http::swap(new \Illuminate\Http\Client\Factory());
            Http::fake(['*personnel_employee' => Http::response($employees), '*cardreadingsdaily' => Http::response($rows)]);
            app(EmployeeService::class)->employeeService();
            app(ReportService::class)->reportsDailyService();
        };

        DB::table('reports')->insert(['employee_id' => $this->people['a1']->id, 'external_id' => 'old_20261001', 'department_name' => 'VİALAND', 'status' => 0]);
        DB::table('reports')->insert(['employee_id' => $this->people['a1']->id, 'external_id' => 'old_20261002', 'department_name' => 'VIALAND', 'status' => 0]);
        $sync($people(40), $row('VİALAND'));

        $department = Department::firstWhere('source_id', 40);
        $report = fn (string $id) => Report::firstWhere('external_id', $id)->department_id;
        $this->assertSame('VİALAND', $department->name);
        $this->assertSame([$department->id, $department->id, null], [$report('n1_20261125'), $report('old_20261001'), $report('old_20261002')]);

        $sync($people(40), $row('YENİ AD'));
        $this->assertSame(['YENİ AD', $department->id], [$department->fresh()->name, $report('n1_20261125')]);

        $sync($people(50), $row('YENİ AD'));
        $this->assertSame(Department::firstWhere('source_id', 50)->id, $report('n1_20261125'));
        $this->assertSame($department->id, $report('old_20261001'));

        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::fake(['*cardreadingsdaily' => Http::response($row('BİLİNMEYEN'))]);
        app(ReportService::class)->reportsDailyService();
        $this->assertNull($report('n1_20261125'));
        $this->assertSame(['a1', 'aPassive', 'hr'], $this->visibleReports($this->hr));
        $this->assertSame(0, app(DepartmentService::class)->fillReports());
    }

    /**
     * @return list<string>
     */
    private function visibleReports(User $user): array
    {
        return AuthorizedReportScope::apply(Report::query(), $user->fresh())->where('date', '2026-11-25')->orderBy('external_id')->get()->pluck('emp_code')->all();
    }

    /**
     * @return list<string>
     */
    private function visibleEmployees(User $user): array
    {
        return AuthorizedEmployeeScope::apply(Employee::query(), $user->fresh())->orderBy('emp_code')->pluck('emp_code')->all();
    }

    private function user(int $employeeId, string $email, string $role, int $createdBy = 1): User
    {
        $user = User::create(['employee_id' => $employeeId, 'name' => $email, 'email' => $email, 'password' => 'x', 'status' => 0, 'created_by' => $createdBy]);
        $user->assignRole($role);

        return $user;
    }

    private function report(string $key, string $date, Department $department): void
    {
        DB::table('reports')->insert([
            'employee_id' => $this->people[$key]->id, 'external_id' => $key . '_' . str_replace('-', '', $date), 'full_name' => "{$key} X",
            'department_id' => $department->id, 'department_name' => $department->name, 'date' => $date, 'status' => $this->people[$key]->status->value,
            'first_reading' => "{$date} 09:00:00", 'last_reading' => "{$date} 18:00:00",
        ]);
    }
}
