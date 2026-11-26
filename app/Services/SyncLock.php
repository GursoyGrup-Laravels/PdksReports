<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Aynı senkronun zamanlanmış görevle veya ikinci bir tıklamayla aynı anda çalışmasını engeller.
 */
class SyncLock
{
    public const EMPLOYEES = 'employees';

    public const REPORTS = 'reports';

    /**
     * @var array<string, int>
     */
    private const SECONDS = [self::EMPLOYEES => 900, self::REPORTS => 1800];

    public static function acquire(string $name, ?User $user = null): ?string
    {
        $lock = Cache::lock("sync:{$name}", self::SECONDS[$name]);

        if (! $lock->get()) {
            return null;
        }

        Cache::put("sync_state:{$name}", ['user_id' => $user?->id, 'started_at' => now()->toDateTimeString()], self::SECONDS[$name]);

        return $lock->owner();
    }

    public static function release(string $name, string $owner): void
    {
        Cache::restoreLock("sync:{$name}", $owner)->release();
        Cache::forget("sync_state:{$name}");
    }

    public static function isRunning(string $name): bool
    {
        return Cache::has("sync_state:{$name}");
    }

    /**
     * Zamanlanmış görevler için: kilit alınamazsa çalışmaz.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    public static function run(string $name, callable $callback): mixed
    {
        $owner = static::acquire($name);

        if ($owner === null) {
            return null;
        }

        try {
            return $callback();
        } finally {
            static::release($name, $owner);
        }
    }
}
