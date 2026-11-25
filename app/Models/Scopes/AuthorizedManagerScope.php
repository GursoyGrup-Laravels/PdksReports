<?php

namespace App\Models\Scopes;

use App\Enums\ManagerTypeEnum;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorizedManagerScope
{
    /**
     * İK tanımlarını yalnızca super_admin görür; diğerleri kapsamındaki amirleri görür.
     * Kaydı oluşturmuş olmak tek başına görme hakkı vermez.
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

        return $query->whereIn('employee_id', AuthorizedEmployeeScope::apply(Employee::query(), $user)->select('employees.id'));
    }
}
