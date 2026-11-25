<?php

namespace App\Services;

use App\Enums\ManagerStatusEnum;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmployeeService
{
    public function __construct(private DepartmentService $departments) {}

    /**
     * @var array<int, int>
     */
    private array $departmentIds = [];

    /**
     * Personelleri emp_code üzerinden eşler; TC isteğe bağlıdır.
     *
     * @return int Yeni eklenen personel sayısı
     */
    public function employeeService(): int
    {
        $response = Http::withToken((string) config('services.pdks.token'))->get('http://10.10.50.6:8080/api/zk/personnel_employee');

        if (!$response->successful()) {
            throw new \Exception("API isteği başarısız oldu: " . $response->status());
        }

        $employees = $response->json();

        // JSON beklenmedik yapıda mı kontrol et
        if (!is_array($employees)) {
            throw new \Exception('API verisi geçersiz veya boş geldi.');
        }

        $this->departmentIds = $this->departments->syncFromEmployees($employees);

        $tcCounts = collect($employees)
            ->map(fn (array $data): ?string => $this->normalizeTcNo($data['nickname'] ?? null))
            ->filter()
            ->countBy();

        $linked = Employee::withTrashed()->whereNotNull('emp_code')->get()->keyBy('emp_code');
        $unlinkedByTc = Employee::query()
            ->whereNull('emp_code')
            ->whereNotNull('tc_no')
            ->where('tc_no', '!=', '')
            ->get()
            ->groupBy('tc_no');

        $count = 0;
        $skipped = ['emp_code_yok' => 0, 'tc_cakismasi' => [], 'hata' => []];

        foreach ($employees as $data) {
            $empCode = trim((string) ($data['emp_code'] ?? ''));

            if ($empCode === '') {
                $skipped['emp_code_yok']++;
                continue;
            }

            try {
                $tcNo = $this->normalizeTcNo($data['nickname'] ?? null);
                $employee = $linked->get($empCode);

                if (! $employee && $tcNo !== null && $unlinkedByTc->has($tcNo)) {
                    $candidates = $unlinkedByTc->get($tcNo);

                    // Aynı TC birden fazla sicile/personele aitse tahmin edilmez
                    if ($candidates->count() > 1 || $tcCounts->get($tcNo) > 1) {
                        $skipped['tc_cakismasi'][] = $empCode;
                        continue;
                    }

                    $employee = $candidates->first();
                }

                $count += $this->saveEmployee($employee, $empCode, $tcNo, $data) ? 1 : 0;
            } catch (\Throwable $e) {
                $skipped['hata'][] = $empCode;
                Log::warning('Personel kaydı işlenemedi', [
                    'emp_code' => $empCode,
                    'exception' => $e::class,
                    'code' => $e->getCode(),
                ]);
            }
        }

        if ($skipped['emp_code_yok'] || $skipped['tc_cakismasi'] || $skipped['hata']) {
            Log::warning('Personel senkronizasyonunda atlanan kayıtlar', $skipped);
        }

        try {
            $this->departments->learnNames($employees);
            $this->departments->fillReports();
        } catch (\Throwable $e) {
            Log::warning('Departman adları güncellenemedi', ['exception' => $e::class, 'mesaj' => $e->getMessage()]);
        }

        return $count;
    }

    /**
     * Elle seçilen personeli bir emp_code'a bağlar (ortak TC çakışmalarını çözmek için).
     */
    public function linkEmployee(Employee $employee, string $empCode): void
    {
        DB::transaction(function () use ($employee, $empCode): void {
            $employee->update(['emp_code' => $empCode]);
            $this->relinkReports($employee, $empCode);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function saveEmployee(?Employee $employee, string $empCode, ?string $tcNo, array $data): bool
    {
        $employee ??= new Employee();
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));

        $attributes = [
            'emp_code' => $empCode,
            'first_name' => $firstName === '' && $lastName === '' ? $empCode : $firstName,
            'last_name' => $lastName,
            'email' => $data['email'] ?? null,
            'status' => ManagerStatusEnum::fromSource($data['status'] ?? null),
            'create_time' => $this->toMysqlDate($data['create_time'] ?? null),
            'update_time' => $this->toMysqlDate($data['update_time'] ?? null),
        ];

        if (is_numeric($data['department_id'] ?? null)) {
            $attributes['department_id'] = $this->departmentIds[(int) $data['department_id']] ?? null;
        }

        if ($tcNo !== null && strlen($tcNo) <= 11) {
            $attributes['tc_no'] = $tcNo;
        }

        return DB::transaction(function () use ($employee, $attributes, $empCode): bool {
            $newlyLinked = $employee->emp_code !== $empCode;
            $employee->fill($attributes)->save();

            if ($newlyLinked) {
                $this->relinkReports($employee, $empCode);
            }

            return $employee->wasRecentlyCreated;
        });
    }

    /**
     * external_id = "{emp_code}_{Ymd}" olduğu için geçmiş raporlar da doğru personele bağlanır.
     */
    private function relinkReports(Employee $employee, string $empCode): void
    {
        $prefix = addcslashes($empCode, '\\%_');

        DB::table('reports')
            ->where('external_id', 'like', $prefix . '\\_________')
            ->where('employee_id', '!=', $employee->id)
            ->update(['employee_id' => $employee->id]);
    }

    private function normalizeTcNo(mixed $value): ?string
    {
        $tcNo = trim((string) $value);

        return preg_match('/^[0-9]{3,}$/', $tcNo) ? $tcNo : null;
    }

    private function toMysqlDate(?string $date)
    {
        if (!$date) return null;

        try {
            return Carbon::parse($date)->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return null;
        }
    }
}
