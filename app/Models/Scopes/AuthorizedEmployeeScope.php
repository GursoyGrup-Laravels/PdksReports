<?php

namespace App\Models\Scopes;

use App\Enums\ManagerStatusEnum;
use App\Models\Manager;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorizedEmployeeScope
{
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin') || $user->can('view_all_employees')) {
            return $query;
        }

        $manager = Manager::where('employee_id', $user->employee_id)->first();

        if (! $manager) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereIn('id', Staff::where('manager_id', $manager->id)->select('employee_id'))
            ->where('status', ManagerStatusEnum::ACTIVE);
    }
}