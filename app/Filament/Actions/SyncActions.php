<?php

namespace App\Filament\Actions;

use App\Jobs\RunSyncJob;
use App\Services\SyncLock;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;

class SyncActions
{
    public static function employees(): Action
    {
        return static::syncButton('syncEmployees', RunSyncJob::EMPLOYEES, SyncLock::EMPLOYEES, 'sync_employees', __('ui.sync_employees'));
    }

    public static function reports(): Action
    {
        return static::syncButton('syncReports', RunSyncJob::REPORTS, SyncLock::REPORTS, 'sync_reports', __('ui.sync_reports'));
    }

    public static function import(): Action
    {
        return Action::make('importReports')
            ->label(__('ui.import_reports'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->can('import_reports') && ! static::hasPreview())
            ->disabled(fn (): bool => SyncLock::isRunning(SyncLock::REPORTS))
            ->modalDescription(fn (): string => __('ui.import_reports_hint', ['size' => static::uploadLimitMb()]))
            ->form([
                FileUpload::make('files')
                    ->label(__('ui.files'))
                    ->multiple()
                    ->required()
                    ->disk('local')
                    ->directory('report-imports')
                    ->visibility('private')
                    ->acceptedFileTypes(['text/plain', 'text/csv', 'application/zip', 'application/x-zip-compressed', 'application/octet-stream'])
                    ->maxSize(static::uploadLimitMb() * 1024)
                    ->maxFiles(12),
            ])
            ->action(fn (array $data) => static::start(RunSyncJob::IMPORT_PREVIEW, SyncLock::REPORTS, array_values((array) $data['files'])));
    }

    public static function confirmImport(): Action
    {
        return Action::make('confirmImport')
            ->label(__('ui.import_confirm'))
            ->icon('heroicon-o-check-circle')
            ->color('warning')
            ->visible(fn (): bool => auth()->user()->can('import_reports') && static::hasPreview())
            ->disabled(fn (): bool => SyncLock::isRunning(SyncLock::REPORTS))
            ->requiresConfirmation()
            ->modalDescription(fn (): string => __('ui.import_preview_done', static::preview()['stats'] ?? []))
            ->action(fn () => static::start(RunSyncJob::IMPORT_WRITE, SyncLock::REPORTS, static::preview()['files'] ?? []));
    }

    public static function cancelImport(): Action
    {
        return Action::make('cancelImport')
            ->label(__('ui.import_cancel'))
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->visible(fn (): bool => auth()->user()->can('import_reports') && static::hasPreview())
            ->disabled(fn (): bool => SyncLock::isRunning(SyncLock::REPORTS))
            ->requiresConfirmation()
            ->action(fn () => RunSyncJob::discardImport(auth()->id()));
    }

    private static function syncButton(string $name, string $task, string $lock, string $permission, string $label): Action
    {
        return Action::make($name)
            ->label(fn (): string => SyncLock::isRunning($lock) ? __('ui.sync_running') : $label)
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => auth()->user()->can($permission))
            ->disabled(fn (): bool => SyncLock::isRunning($lock))
            ->requiresConfirmation()
            ->modalDescription(__('ui.sync_confirm'))
            ->action(fn () => static::start($task, $lock));
    }

    /**
     * @param  list<string>  $files
     */
    private static function start(string $task, string $lock, array $files = []): void
    {
        $owner = SyncLock::acquire($lock, auth()->user());

        if ($owner === null) {
            if ($task === RunSyncJob::IMPORT_PREVIEW) {
                RunSyncJob::deleteFiles($files);
            }

            Notification::make()->title(__('ui.sync_already_running'))->warning()->send();

            return;
        }

        RunSyncJob::dispatch($task, $owner, auth()->id(), $files);
        Notification::make()->title(__('ui.sync_started'))->body(__('ui.sync_started_hint'))->success()->send();
    }

    /**
     * @return array{files: list<string>, hash: string, stats: array<string, int>}|null
     */
    private static function preview(): ?array
    {
        return Cache::get(RunSyncJob::previewKey(auth()->id()));
    }

    private static function hasPreview(): bool
    {
        return Cache::has(RunSyncJob::previewKey(auth()->id()));
    }

    private static function uploadLimitMb(): int
    {
        $bytes = fn (string $value): int => (int) $value * match (strtoupper(substr(trim($value), -1))) {
            'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1,
        };

        return max(1, intdiv(min($bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size')), 12 * 1024 ** 2), 1024 ** 2));
    }
}
