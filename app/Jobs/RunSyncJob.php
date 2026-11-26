<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\EmployeeService;
use App\Services\ReportService;
use App\Services\SyncLock;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Panelden başlatılan senkronlar. Kilit butona basıldığında alınır, iş bitince bırakılır.
 */
class RunSyncJob implements ShouldQueue
{
    use Queueable;

    public const EMPLOYEES = 'employees';

    public const REPORTS = 'reports';

    public const IMPORT_PREVIEW = 'import_preview';

    public const IMPORT_WRITE = 'import_write';

    public int $tries = 1;

    public int $timeout = 1500;

    /**
     * @param  list<string>  $files
     */
    public function __construct(
        public string $task,
        public string $lockOwner,
        public int $userId,
        public array $files = [],
    ) {}

    public function handle(EmployeeService $employees, ReportService $reports): void
    {
        try {
            Log::info('Panelden senkron başladı', ['gorev' => $this->task, 'kullanici' => $this->userId]);

            $body = match ($this->task) {
                self::EMPLOYEES => __('ui.sync_employees_done', ['count' => $employees->employeeService()]),
                self::REPORTS => $this->syncReports($reports),
                self::IMPORT_PREVIEW => $this->preview($reports),
                self::IMPORT_WRITE => $this->write($reports),
            };

            Log::info('Panelden senkron bitti', ['gorev' => $this->task, 'kullanici' => $this->userId]);
            $this->notify(__('ui.sync_finished'), $body, 'success');
        } catch (\Throwable $e) {
            Log::error('Panelden senkron başarısız', ['gorev' => $this->task, 'kullanici' => $this->userId, 'hata' => $e->getMessage()]);
            $this->notify(__('ui.sync_failed'), $e->getMessage(), 'danger');

            if ($this->task === self::IMPORT_PREVIEW) {
                static::deleteFiles($this->files);
            } elseif ($this->task === self::IMPORT_WRITE) {
                static::discardImport($this->userId);
            }
        } finally {
            SyncLock::release($this->lockName(), $this->lockOwner);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        SyncLock::release($this->lockName(), $this->lockOwner);
    }

    public static function previewKey(int $userId): string
    {
        return "report_import_preview:{$userId}";
    }

    public static function discardImport(int $userId): void
    {
        static::deleteFiles(Cache::pull(static::previewKey($userId))['files'] ?? []);
    }

    /**
     * @param  list<string>  $files
     */
    public static function deleteFiles(array $files): void
    {
        foreach ($files as $file) {
            Storage::disk('local')->delete($file);
        }
    }

    private function syncReports(ReportService $reports): string
    {
        $today = $reports->reportsDailyService();
        $recent = $reports->syncRecent();

        return __('ui.sync_reports_done', ['today' => $today, 'added' => $recent['eklenen'], 'completed' => $recent['tamamlanan']]);
    }

    private function preview(ReportService $reports): string
    {
        $stats = $reports->importFiles($this->paths(), false);
        Cache::put(static::previewKey($this->userId), ['files' => $this->files, 'hash' => $this->hash(), 'stats' => $stats], now()->addDay());

        return __('ui.import_preview_done', $stats);
    }

    private function write(ReportService $reports): string
    {
        $preview = Cache::get(static::previewKey($this->userId));

        if (! $preview || $preview['files'] !== $this->files || $preview['hash'] !== $this->hash()) {
            throw new \Exception(__('ui.import_preview_missing'));
        }

        $stats = $reports->importFiles($this->paths(), true);
        static::discardImport($this->userId);

        return __('ui.import_write_done', $stats);
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return array_map(fn (string $file): string => Storage::disk('local')->path($file), $this->files);
    }

    private function hash(): string
    {
        return hash('sha256', implode('|', array_map(fn (string $path): string => (string) @hash_file('sha256', $path), $this->paths())));
    }

    private function lockName(): string
    {
        return $this->task === self::EMPLOYEES ? SyncLock::EMPLOYEES : SyncLock::REPORTS;
    }

    private function notify(string $title, string $body, string $status): void
    {
        $user = User::find($this->userId);

        if ($user) {
            Notification::make()->title($title)->body($body)->status($status)->sendToDatabase($user);
        }
    }
}
