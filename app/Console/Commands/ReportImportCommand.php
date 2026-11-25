<?php

namespace App\Console\Commands;

use App\Services\ReportService;
use Illuminate\Console\Command;

class ReportImportCommand extends Command
{
    protected $signature = 'reports:import {file : VW_PDKS_LAST_90_DAYS_REPORT sekmeyle ayrılmış çıktısı} {--write : Önizleme yerine veritabanına yaz}';

    protected $description = 'Kaynak view çıktısından geçmiş günleri tamamlar (varsayılan: yalnızca önizleme)';

    public function handle(ReportService $reportService): int
    {
        try {
            $stats = $reportService->importFile((string) $this->argument('file'), (bool) $this->option('write'));
            $this->info(($this->option('write') ? '✅ Yazıldı: ' : '🔎 Önizleme: ') . json_encode($stats, JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('❌ Hata: ' . ($e instanceof \Illuminate\Database\QueryException ? 'Veritabanı hatası ' . $e->getCode() : $e->getMessage()));

            return Command::FAILURE;
        }
    }
}
