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
    private function handleRecentBatch(array $rows, array &$stats, bool $write = true): void
    {
        $existing = DB::table('reports')
            ->whereIn('external_id', array_column($rows, 'external_id'))
            ->get(['external_id', 'first_reading', 'last_reading'])
            ->keyBy('external_id');

        $changed = [];

        foreach ($rows as $row) {
            $old = $existing->get($row['external_id']);

            if ($old) {
                if ($row['first_reading'] === null) {
                    $stats['degismeyen']++;
                    continue;
                }

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

        if ($write && $changed !== []) {
            DB::table('reports')->upsert($changed, ['external_id'], ['first_reading', 'last_reading', 'working_time', 'updated_at']);
        }
    }

    /**
     * Sekmeyle ayrılmış view çıktısını aktarır. Dosyanın tamamı doğrulanmadan hiçbir satır yazılmaz;
     * mevcut saatler silinmez veya daraltılmaz.
     *
     * @return array{eklenen: int, tamamlanan: int, degismeyen: int, gun: int, satir: int, personelsiz: int, basan: int}
     */
    public function importFile(string $path, bool $write): array
    {
        $rows = $this->readImportFile($path);
        $dates = array_column($rows, 'date');
        $stats = [
            'eklenen' => 0, 'tamamlanan' => 0, 'degismeyen' => 0,
            'gun' => count(array_unique($dates)), 'satir' => count($rows), 'personelsiz' => 0,
            'basan' => count(array_filter(array_column($rows, 'first_reading'))),
        ];

        $apply = function () use ($rows, &$stats, $write): void {
            foreach (array_chunk($rows, 1000) as $chunk) {
                $resolved = $this->resolveEmployees($chunk);
                $stats['personelsiz'] += count($chunk) - count($resolved);
                $this->handleRecentBatch($resolved, $stats, $write);
            }
        };

        $write ? DB::transaction($apply) : $apply();
        Log::info($write ? 'Rapor dosyası aktarıldı' : 'Rapor dosyası önizlendi', $stats);

        return $stats;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readImportFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new \Exception('Dosya okunamadı.');
        }

        $content = (string) file_get_contents($path);

        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            throw new \Exception('Dosya UTF-8 veya Unicode olmalı.');
        }

        $columns = ['external_id', 'tc_no', 'full_name', 'department_name', 'position_name', 'date', 'day', 'first_reading', 'last_reading', 'working_time', 'status'];
        $rows = [];
        $perDay = [];

        foreach (preg_split('/\r\n|\n|\r/', $content) as $number => $line) {
            if (trim($line) === '' || str_starts_with($line, "external_id\t")) {
                continue;
            }

            $values = array_map(fn (string $value): ?string => in_array(trim($value), ['NULL', ''], true) ? null : trim($value), explode("\t", $line));
            $label = 'satır ' . ($number + 1);

            if (count($values) !== count($columns)) {
                throw new \Exception("Kolon sayısı hatalı: {$label}");
            }

            $data = array_combine($columns, $values);
            $row = $this->formatData($data);
            $date = $row ? Carbon::createFromFormat('!Ymd', substr($row['external_id'], -8))->toDateString() : null;

            if ($row === null || substr((string) $data['date'], 0, 10) !== $date || isset($rows[$row['external_id']])) {
                throw new \Exception("Geçersiz veya tekrarlı kayıt: {$label}");
            }

            if ($date >= today()->toDateString() || $date < today()->subDays(90)->toDateString()) {
                throw new \Exception("Tarih aralık dışında: {$label}");
            }

            foreach (['first_reading', 'last_reading'] as $field) {
                if (($data[$field] !== null) !== ($data['first_reading'] !== null) || ($data[$field] !== null && ! str_starts_with((string) $row[$field], $date))) {
                    throw new \Exception("Okutma saati geçersiz: {$label}");
                }
            }

            $row['date'] = $date;
            $row['working_time'] = $row['first_reading'] === null ? null : self::workingTime($row['first_reading'], $row['last_reading']);
            $rows[$row['external_id']] = $row;
            $perDay[$date] = ($perDay[$date] ?? 0) + 1;
        }

        if ($perDay === [] || count(array_unique($perDay)) !== 1) {
            throw new \Exception('Günlerin satır sayısı eşit değil; dosya eksik olabilir.');
        }

        if (count($perDay) !== (int) Carbon::parse(min(array_keys($perDay)))->diffInDays(Carbon::parse(max(array_keys($perDay)))) + 1) {
            throw new \Exception('Dosyada eksik gün var.');
        }

        return array_values($rows);
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