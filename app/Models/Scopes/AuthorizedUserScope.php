<?php

namespace App\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class AuthorizedUserScope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    const PHANTOM_USER_ID = 1; // ID of the phantom user to exclude from queries

    public static function apply(Builder $query, User $user): Builder
    {
        $query
            ->where('id', '!=', $user->id)
            ->where('id', '!=', static::PHANTOM_USER_ID);

        if ($user->hasRole('super_admin') || $user->can('view_all_users')) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }
}
