<?php

namespace App\Models\Scopes;

use App\Enums\ManagerStatusEnum;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Report;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorizedReportScope
{
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin') || $user->can('view_all_reports')) {
            return $query;
        }

        $employeeIds = once(fn () => static::resolveEmployeeIds($user));

        return $employeeIds->isEmpty()
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('employee_id', $employeeIds->toArray());
    }

    /**
     * Kullanıcının görebildiği aktif personelin bugünkü sayıları.
     *
     * @return array{expected: int, checked: int, not_checked: int}
     */
    public static function todayCounts(User $user): array
    {
        return once(function () use ($user): array {
            $counts = static::apply(Report::query(), $user)
                ->where('date', '>=', today())
                ->where('date', '<', today()->addDay())
                ->where('status', ManagerStatusEnum::ACTIVE)
                ->toBase()
                ->selectRaw('COUNT(*) as expected, COUNT(first_reading) as checked')
                ->first();

            return [
                'expected' => (int) $counts->expected,
                'checked' => (int) $counts->checked,
                'not_checked' => $counts->expected - $counts->checked,
            ];
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private static function resolveEmployeeIds(User $user): \Illuminate\Support\Collection
    {
        $employeeIds = collect();

        if ($user->employee) {
            $employeeIds->push($user->employee->id);
        }

        $manager = Manager::where('employee_id', $user->employee_id)->first();

        if ($manager) {
            $staffEmployeeIds = Employee::where('status', ManagerStatusEnum::ACTIVE)
                ->whereIn('id',
                    Staff::where('manager_id', $manager->id)->select('employee_id')
                )
                ->pluck('id');

            $employeeIds = $employeeIds->merge($staffEmployeeIds)->unique();
        }

        return $employeeIds;
    }
}
