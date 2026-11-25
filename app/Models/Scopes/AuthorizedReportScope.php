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
