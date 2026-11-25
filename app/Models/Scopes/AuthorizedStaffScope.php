<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AuthorizedStaffScope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin') || $user->can('view_all_staff')) {
            return $query;
        }

        $manager = UserAccess::manager($user);

        if (! $manager) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('manager_id', $manager->id);
    }
}
