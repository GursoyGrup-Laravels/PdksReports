<?php

namespace App\Services;

use App\Enums\ManagerStatusEnum;
use App\Models\Report;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReportService
{
    /**
     * Günlük rapor.
     */
    public function reportsDailyService(): int
    {
        return $this->processReports(
            'http://10.10.50.6:8080/api/zk/cardreadingsdaily',
            60
        );
    }

    /**
     * Kaynak uç yalnızca son 9 günü verir.
     */
    public function reportsLast90DaysService(): int
    {
        $stats = $this->syncRecent();

        return $stats['eklenen'] + $stats['tamamlanan'];
    }

    /**
     * Uç, kişi ve gün başına her terminal için ayrı satır döndürür; satırlar en erken/en geç okutmada birleştirilir.
     * Mevcut saat hiçbir zaman daraltılmaz veya silinmez, o günkü departman ve durum korunur.
     * Bugün günlük senkrona aittir; penceredeki ilk gün eksik geldiği için atlanır.
     *
     * @return array{eklenen: int, tamamlanan: int, degismeyen: int, gun: int}
     */
    public function syncRecent(): array
    {
        $response = Http::withToken((string) config('services.pdks.token'))
            ->timeout(300)
            ->get('http://10.10.50.6:8080/api/zk/cardreadingsninedays');

        if ($response->failed() || ! is_array($response->json()) || $response->json() === []) {
            throw new \Exception('Son 9 gün raporu alınamadı. HTTP Kod: ' . $response->status());
        }

        $merged = $this->mergeTerminalRows($response->json());
        $stats = ['eklenen' => 0, 'tamamlanan' => 0, 'degismeyen' => 0, 'gun' => count(array_unique(array_column($merged, 'date')))];

        foreach (array_chunk($merged, 1000) as $chunk) {
            $this->handleRecentBatch($this->resolveEmployees($chunk), $stats);
        }

        Log::info('Son 9 gün tamamlandı', $stats);

        return $stats;
    }

    /**
     * @param  list<array<string, mixed>>  $dataList
     * @return list<array<string, mixed>>
     */
    private function mergeTerminalRows(array $dataList): array
    {
        $merged = [];

        foreach ($dataList as $data) {
            $row = $this->formatData($data);

            if ($row === null || $row['first_reading'] === null || $row['last_reading'] === null) {
                continue;
            }

            $date = Carbon::createFromFormat('!Ymd', substr($row['external_id'], -8))->toDateString();

            if (! str_starts_with($row['first_reading'], $date) || ! str_starts_with($row['last_reading'], $date)) {
                continue;
            }

            $row['date'] = $date;
            $known = $merged[$row['external_id']] ?? $row;
            $row['first_reading'] = min($row['first_reading'], $known['first_reading']);
            $row['last_reading'] = max($row['last_reading'], $known['last_reading']);
            $row['working_time'] = self::workingTime($row['first_reading'], $row['last_reading']);
            $merged[$row['external_id']] = $row;
        }

        if ($merged === []) {
            return [];
        }

        $firstDay = min(array_column($merged, 'date'));
        $today = today()->toDateString();

        return array_values(array_filter($merged, fn (array $row): bool => $row['date'] > $firstDay && $row['date'] < $today));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $stats
     */
    private function handleRecentBatch(array $rows, array &$stats): void
    {
        $existing = DB::table('reports')
            ->whereIn('external_id', array_column($rows, 'external_id'))
            ->get(['external_id', 'first_reading', 'last_reading'])
            ->keyBy('external_id');

        $changed = [];

        foreach ($rows as $row) {
            $old = $existing->get($row['external_id']);

            if ($old) {
                $row['first_reading'] = min($row['first_reading'], $old->first_reading ?? $row['first_reading']);
                $row['last_reading'] = max($row['last_reading'], $old->last_reading ?? $row['last_reading']);

                if ($row['first_reading'] === $old->first_reading && $row['last_reading'] === $old->last_reading) {
                    $stats['degismeyen']++;
                    continue;
                }

                $row['working_time'] = self::workingTime($row['first_reading'], $row['last_reading']);
            }

            $stats[$old ? 'tamamlanan' : 'eklenen']++;
            $changed[] = $row;
        }

        if ($changed !== []) {
            DB::table('reports')->upsert($changed, ['external_id'], ['first_reading', 'last_reading', 'working_time', 'updated_at']);
        }
    }

    private static function workingTime(string $first, string $last): string
    {
        return gmdate('H:i:s', (int) Carbon::parse($first)->diffInSeconds(Carbon::parse($last)));
    }

    /**
     * Ortak işlem metodu.
     */
    protected function processReports(string $apiUrl, int $timeout): int
    {
        $batchSize = 1000;
        $buffer = [];
        $totalProcessed = 0;

        try {
            Log::info("Rapor çekiliyor: {$apiUrl}");

            $response = Http::withToken((string) config('services.pdks.token'))->timeout($timeout)->get($apiUrl);

            if ($response->failed()) {
                Log::error('API hatası', [
                    'url' => $apiUrl,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \Exception("API isteği başarısız oldu. HTTP Kod: " . $response->status());
            }

            $dataList = $response->json();

            foreach ($dataList as $data) {

                $formatted = $this->formatData($data);

                if ($formatted !== null) {
                    $buffer[] = $formatted;
                }

                if (count($buffer) >= $batchSize) {
                    $totalProcessed += $this->handleBatch($buffer);
                    $buffer = [];
                }
            }

            if (!empty($buffer)) {
                $totalProcessed += $this->handleBatch($buffer);
            }

            Log::info("Rapor tamamlandı. Toplam kayıt: {$totalProcessed}");
            return $totalProcessed;

        } catch (\Throwable $e) {
            Log::error('Rapor hatası: ' . $e->getMessage(), [
                'exception' => $e
            ]);
            throw $e;
        }
    }

    /**
     * Batch işle: employee eşleme + upsert.
     */
    protected function handleBatch(array $buffer): int
    {
        $rows = $this->resolveEmployees($buffer);

        if ($rows !== []) {
            $this->upsertReports($rows);
        }

        return count($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $buffer
     * @return list<array<string, mixed>>
     */
    private function resolveEmployees(array $buffer): array
    {
        $empCodes = array_map(fn (array $row): string => (string) Report::empCodeFromExternalId($row['external_id']), $buffer);
        $tcNos = array_values(array_filter(array_column($buffer, 'tc_no')));

        $byEmpCode = DB::table('employees')
            ->whereIn('emp_code', $empCodes)
            ->pluck('id', 'emp_code');

        // Henüz emp_code ile eşlenmemiş personeller için önceki TC eşlemesi korunur
        $byTcNo = DB::table('employees')
            ->whereNull('emp_code')
            ->whereNull('deleted_at')
            ->whereIn('tc_no', $tcNos)
            ->pluck('id', 'tc_no');

        $rows = [];

        foreach ($buffer as $index => $row) {
            $row['employee_id'] = $byEmpCode->get($empCodes[$index])
                ?? ($row['tc_no'] !== null ? $byTcNo->get($row['tc_no']) : null);

            if ($row['employee_id'] !== null) {
                $rows[] = $row;
            }
        }

        if (count($rows) < count($buffer)) {
            Log::warning('Personeli bulunamayan raporlar atlandı', ['adet' => count($buffer) - count($rows)]);
        }

        return $rows;
    }

    /**
     * Kayıt formatlama.
     */
    protected function formatData(array $data): ?array
    {
        if (Report::empCodeFromExternalId($data['external_id'] ?? null) === null) {
            return null;
        }

        $tc = trim((string) ($data['tc_no'] ?? ''));
        $tc = preg_match('/^[0-9]+$/', $tc) ? $tc : null;

        return [
            'external_id'     => $data['external_id'] ?? null,
            'tc_no'           => $tc,
            'full_name'       => $data['full_name'] ?? null,
            'department_name' => $data['department_name'] ?? null,
            'position_name'   => $data['position_name'] ?? null,
            'date'            => $data['date'] ?? null,
            'day'             => $data['day'] ?? null,
            'first_reading'   => self::parseDate($data['first_reading'] ?? null),
            'last_reading'    => self::parseDate($data['last_reading'] ?? null),
            'working_time'    => $data['working_time'] ?? null,
            'status'          => ManagerStatusEnum::fromSource($data['status'] ?? null)->value,
            'created_at'      => now(),
            'updated_at'      => now(),
        ];
    }

//    protected function formatData(array $data): ?array
//    {
//        if (empty($data['tc_no'])) {
//            return null;
//        }
//
//        return [
//            'external_id'     => $data['external_id'] ?? null,
//            'tc_no'           => $data['tc_no'],
//            'full_name'       => $data['full_name'] ?? null,
//            'department_name' => $data['department_name'] ?? null,
//            'position_name'   => $data['position_name'] ?? null,
//            'date'            => $data['date'] ?? null,
//            'day'             => $data['day'] ?? null,
//            'first_reading'   => self::parseDate($data['first_reading'] ?? null),
//            'last_reading'    => self::parseDate($data['last_reading'] ?? null),
//            'working_time'    => $data['working_time'] ?? null,
//            'status'          => $data['status'] ?? null,
//            'created_at'      => now(),
//            'updated_at'      => now(),
//        ];
//    }

    /**
     * Tarih formatlama.
     */
    protected static function parseDate(?string $value): ?string
    {
        if (empty($value) || $value === '?') {
            return null;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d+$/', $value)) {
                try {
                    return Carbon::createFromFormat('Y-m-d H:i:s.u', $value)
                        ->format('Y-m-d H:i:s');
                } catch (\Exception $ex) {
                    Log::warning('Tarih dönüştürme hatası (milisaniyeli)', ['value' => $value]);
                    return null;
                }
            }

            Log::warning('Tarih dönüştürme hatası', ['value' => $value]);
            return null;
        }
    }

    /**
     * Upsert işlemi.
     */
    protected function upsertReports(array $buffer): void
    {
        DB::table('reports')->upsert(
            $buffer,
            ['external_id'], // eşsiz alan
            [
                'employee_id',
                'tc_no',
                'full_name',
                'department_name',
                'position_name',
                'date',
                'day',
                'first_reading',
                'last_reading',
                'working_time',
                'status',
                'updated_at',
            ]
        );
    }
}