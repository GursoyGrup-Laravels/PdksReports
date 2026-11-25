<?php

namespace App\Models\Scopes;

use App\Enums\ManagerStatusEnum;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class AuthorizedReportScope
{
    public static function apply(Builder $query, User $user): Builder
    {
        if ($user->hasRole('super_admin') || $user->can('view_all_reports')) {
            return $query;
        }

        [$employeeIds, $departmentIds] = once(fn () => [static::resolveEmployeeIds($user), UserAccess::departmentIds($user)]);

        return $query->where(fn (Builder $query) => $query
            ->whereIn('reports.employee_id', $employeeIds)
            ->orWhereIn('reports.department_id', $departmentIds));
    }

    /**
     * Kullanıcının görebildiği aktif personelin bugünkü sayıları.
     *
     * @return array{expected: int, checked: int, not_checked: int}
     */
    public static function todayCounts(User $user): array
    {
        return once(function () use ($user): array {
            $counts = static::apply(Report::query(), $user)
                ->where('date', '>=', today())
                ->where('date', '<', today()->addDay())
                ->where('status', ManagerStatusEnum::ACTIVE)
                ->toBase()
                ->selectRaw('COUNT(*) as expected, COUNT(first_reading) as checked')
                ->first();

            return [
                'expected' => (int) $counts->expected,
                'checked' => (int) $counts->checked,
                'not_checked' => $counts->expected - $counts->checked,
            ];
        });
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

        $employeeIds = $employeeIds->merge(UserAccess::staffIds($user))->unique()->values();

        return $employeeIds;
    }
}
