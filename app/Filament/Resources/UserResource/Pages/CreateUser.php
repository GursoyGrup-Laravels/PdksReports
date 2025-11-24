<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Models\Employee;
use App\Models\Report;
use App\Notifications\UserCreated;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()->id;
        $data['tc_no'] = $data['employee_id'];

        $employee = Employee::find($data['employee_id']);

        if ($employee) {
            $data['name'] = $employee->full_name;
        } else {
            session()->flash('error', 'Çalışan bulunamadı.');
        }

        // 8 hanelik alfanümerik şifre üret
        $this->generatedPassword = Str::random(8);

        $data['password'] = $this->generatedPassword;

        return $data;
    }

    protected function afterCreate(): void
    {
        // Filament create işleminden sonra notification gönder
        $this->record->notify(
            new UserCreated($this->record, $this->generatedPassword)
        );
    }
}
