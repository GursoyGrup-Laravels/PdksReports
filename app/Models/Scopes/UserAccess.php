<?php

namespace App\Models\Scopes;

use App\Enums\ManagerStatusEnum;
use App\Enums\ManagerTypeEnum;
use App\Models\Employee;
use App\Models\Manager;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UserAccess
{
    /**
     * Pasif yetkilinin kapsamı yoktur.
     */
    public static function manager(User $user): ?Manager
    {
        if (! $user->employee_id) {
            return null;
        }

        return Manager::where('employee_id', $user->employee_id)
            ->where('status', ManagerStatusEnum::ACTIVE)
            ->first();
    }

    /**
     * Yalnızca İK tipi yetkili departman kapsamı alır.
     *
     * @return Collection<int, int>
     */
    public static function departmentIds(User $user): Collection
    {
        $manager = static::manager($user);

        return $manager?->type === ManagerTypeEnum::HR
            ? $manager->departments()->pluck('departments.id')
            : collect();
    }

    /**
     * Atanmış aktif personel.
     *
     * @return Collection<int, int>
     */
    public static function staffIds(User $user): Collection
    {
        $manager = static::manager($user);

        return $manager
            ? Employee::where('status', ManagerStatusEnum::ACTIVE)
                ->whereIn('id', Staff::where('manager_id', $manager->id)->select('employee_id'))
                ->pluck('id')
            : collect();
    }

    public static function hasFullEmployeeAccess(User $user): bool
    {
        return $user->hasRole('super_admin') || $user->can('view_all_employees');
    }

    /**
     * Kullanıcının seçim listelerinde görebileceği aktif personel.
     */
    public static function selectableEmployees(User $user): Builder
    {
        return AuthorizedEmployeeScope::apply(Employee::query(), $user)->where('status', ManagerStatusEnum::ACTIVE);
    }
}
