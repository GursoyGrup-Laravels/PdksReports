<?php

namespace App\Models\Scopes;

use App\Enums\ManagerTypeEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorizedManagerScope
{
    /**
     * İK tanımlarını yalnızca super_admin görür; İK, seçili departmanlarındaki amirleri görür.
     */
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin')) {
            return $query;
        }

        $query->where('type', ManagerTypeEnum::MANAGER);

        if ($user->can('view_all_managers')) {
            return $query;
        }

        $departmentIds = UserAccess::departmentIds($user);

        return $query->where(fn (Builder $query) => $query
            ->where('created_by', $user->id)
            ->orWhereHas('employee', fn (Builder $query) => $query->whereIn('department_id', $departmentIds)));
    }
}
