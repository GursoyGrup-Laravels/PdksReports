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

        $tcNos = once(fn () => static::resolveTcNos($user));

        return $tcNos->isEmpty()
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('tc_no', $tcNos->toArray());
    }

    private static function resolveTcNos(User $user): \Illuminate\Support\Collection
    {
        $tcNos = collect();

        if ($tcNo = $user->employee?->tc_no) {
            $tcNos->push($tcNo);
        }

        $manager = Manager::where('employee_id', $user->employee_id)->first();

        if ($manager) {
            $staffTcNos = Employee::where('status', ManagerStatusEnum::ACTIVE)
                ->whereIn('id',
                    Staff::where('manager_id', $manager->id)->select('employee_id')
                )
                ->pluck('tc_no');

            $tcNos = $tcNos->merge($staffTcNos)->unique();
        }

        return $tcNos;
    }
}