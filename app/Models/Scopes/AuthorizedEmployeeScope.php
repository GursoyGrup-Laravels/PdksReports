<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorizedEmployeeScope
{
    public static function apply(Builder $query, User $user): Builder
    {
        if (UserAccess::hasFullEmployeeAccess($user)) {
            return $query;
        }

        $departmentIds = UserAccess::departmentIds($user);
        $staffIds = UserAccess::staffIds($user);

        return $query->where(fn (Builder $query) => $query
            ->whereIn('employees.id', $staffIds)
            ->orWhereIn('employees.department_id', $departmentIds));
    }
}
