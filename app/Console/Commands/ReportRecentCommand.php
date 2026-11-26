<?php

namespace App\Console\Commands;

use App\Services\ReportService;
use Illuminate\Console\Command;

class ReportRecentCommand extends Command
{
    protected $signature = 'reports:sync-recent';

    protected $description = 'Son 9 günün kart okutmalarını birleştirip eksik saatleri tamamlar';

    public function handle(ReportService $reportService): int
    {
        try {
            $stats = \App\Services\SyncLock::run(\App\Services\SyncLock::REPORTS, fn () => $reportService->syncRecent());
            $this->info($stats === null ? 'Başka bir senkron çalışıyor, atlandı.' : '✅ ' . json_encode($stats));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Hata: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
