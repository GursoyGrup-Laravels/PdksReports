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
            $this->info('✅ ' . json_encode($reportService->syncRecent()));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Hata: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
