<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Report;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DepartmentService
{
    /**
     * @param  list<array<string, mixed>>  $employees
     * @return array<int, int> Kaynak departman numarası => departments.id
     */
    public function syncFromEmployees(array $employees): array
    {
        $sourceIds = collect($employees)->pluck('department_id')->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique();

        foreach ($sourceIds as $sourceId) {
            Department::firstOrCreate(['source_id' => $sourceId]);
        }

        return Department::pluck('id', 'source_id')->all();
    }

    /**
     * Departman adları kaynakta yalnızca rapor satırlarında bulunur. Ad, yalnızca tek bir departman
     * numarasıyla birebir eşleşiyorsa öğrenilir; belirsiz adlar kimseye yetki vermez.
     *
     * @param  list<array<string, mixed>>  $employees
     */
    public function learnNames(array $employees): void
    {
        $response = Http::withToken((string) config('services.pdks.token'))
            ->timeout(60)
            ->get('http://10.10.50.6:8080/api/zk/cardreadingsdaily');

        if ($response->failed() || ! is_array($response->json())) {
            throw new \Exception('Departman adları alınamadı. HTTP Kod: ' . $response->status());
        }

        $sourceIds = collect($employees)->mapWithKeys(fn (array $row): array => [trim((string) ($row['emp_code'] ?? '')) => $row['department_id'] ?? null]);
        $idsByName = [];
        $namesById = [];

        foreach ($response->json() as $row) {
            $sourceId = $sourceIds->get(Report::empCodeFromExternalId($row['external_id'] ?? null) ?? '');
            $name = trim((string) ($row['department_name'] ?? ''));

            if ($name !== '' && is_numeric($sourceId)) {
                $idsByName[$name][(int) $sourceId] = true;
                $namesById[(int) $sourceId][$name] = true;
            }
        }

        $departments = Department::all()->keyBy('source_id');
        $owners = DB::table('department_names')->pluck('department_id', 'name');

        foreach ($idsByName as $name => $ids) {
            $name = (string) $name;
            $sourceId = array_key_first($ids);
            $department = $departments->get($sourceId);

            if (count($ids) !== 1 || count($namesById[$sourceId]) !== 1 || ! $department) {
                Log::warning('Departman adı eşleştirilemedi', ['ad' => $name, 'kaynak' => array_keys($ids)]);
                continue;
            }

            if ($department->name !== $name) {
                $department->update(['name' => $name]);
            }

            // Ad başka departmana geçtiyse yeni satırlar eski departmanın yetkilisine gitmez
            if ((int) ($owners[$name] ?? 0) !== $department->id) {
                DB::table('department_names')->updateOrInsert(['name' => $name], ['department_id' => $department->id, 'updated_at' => now()]);
            }
        }
    }

    /**
     * Departmanı boş raporları, bilinen adlarına göre parça parça doldurur.
     */
    public function fillReports(): int
    {
        $filled = 0;
        $lastId = 0;

        do {
            $ids = DB::table('reports')->whereNull('department_id')->where('id', '>', $lastId)->orderBy('id')->limit(10000)->pluck('id');

            if ($ids->isNotEmpty()) {
                $lastId = $ids->last();
                $filled += DB::update(
                    'update reports r join department_names n on n.name = convert(trim(r.department_name) using utf8mb4) collate utf8mb4_bin
                     set r.department_id = n.department_id where r.department_id is null and r.id between ? and ?',
                    [$ids->first(), $lastId]
                );
            }
        } while ($ids->count() === 10000);

        return $filled;
    }

    /**
     * @return array<string, int>
     */
    public function idsByName(): array
    {
        return DB::table('department_names')->pluck('department_id', 'name')->all();
    }
}
