<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Enums\ManagerStatusEnum;
use App\Filament\Resources\ReportResource;
use App\Models\Report;
use App\Models\Scopes\AuthorizedReportScope;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListReports extends ListRecords
{
    protected static string $resource = ReportResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $today = Carbon::today()->toDateString();

        $reportQuery = AuthorizedReportScope::apply(Report::query(), auth()->user());

        $todayReports    = (clone $reportQuery)->whereDate('date', $today);
        $allCount        = (clone $reportQuery)->count();
        $checkedCount    = (clone $todayReports)->whereNotNull('first_reading')->where('status', ManagerStatusEnum::ACTIVE)->count();
        $notCheckedCount = (clone $todayReports)->whereNull('first_reading')->whereNull('last_reading')->where('status', ManagerStatusEnum::ACTIVE)->count();

        return [
            'all' => Tab::make(__('ui.all'))
                ->badge($allCount)
                ->badgeIcon('heroicon-o-rectangle-stack')
                ->modifyQueryUsing(fn ($query) => $query),

            'checked' => Tab::make(__('ui.checked'))
                ->badge($checkedCount)
                ->badgeColor('success')
                ->badgeIcon('heroicon-o-finger-print')
                ->modifyQueryUsing(fn ($query) =>
                $query->whereDate('date', $today)->whereNotNull('first_reading')
                ),

            'not_checked' => Tab::make(__('ui.not_checked'))
                ->badge($notCheckedCount)
                ->badgeColor('warning')
                ->badgeIcon('heroicon-o-no-symbol')
                ->modifyQueryUsing(fn ($query) =>
                $query->whereDate('date', $today)
                    ->whereNull('first_reading')
                    ->whereNull('last_reading')
                    ->where('status', ManagerStatusEnum::ACTIVE)
                ),
        ];
    }
}