<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AuthorizedManagerScope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin') || $user->can('view_all_managers')) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }
}
