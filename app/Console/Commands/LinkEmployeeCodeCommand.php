<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\EmployeeService;
use Illuminate\Console\Command;

class LinkEmployeeCodeCommand extends Command
{
    protected $signature = 'employee:link-code {employee : Personel id} {emp_code : BioTime emp_code}';

    protected $description = 'Ortak TC nedeniyle otomatik eşlenemeyen personeli bir emp_code ile eşler';

    public function handle(EmployeeService $employeeService): int
    {
        $employee = Employee::find($this->argument('employee'));
        $empCode = trim((string) $this->argument('emp_code'));

        if (! $employee || $empCode === '') {
            $this->error('Personel veya emp_code bulunamadı.');

            return Command::FAILURE;
        }

        if ($employee->emp_code !== null || Employee::withTrashed()->where('emp_code', $empCode)->exists()) {
            $this->error('Personel veya emp_code zaten eşlenmiş.');

            return Command::FAILURE;
        }

        $employeeService->linkEmployee($employee, $empCode);
        $this->info("Personel #{$employee->id} → {$empCode} eşlendi.");

        return Command::SUCCESS;
    }
}
