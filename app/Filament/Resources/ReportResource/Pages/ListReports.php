<?php

namespace App\Filament\Resources\ReportResource\Pages;

use App\Enums\ManagerStatusEnum;
use App\Filament\Actions\SyncActions;
use App\Filament\Resources\ReportResource;
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
            SyncActions::reports(),
            SyncActions::import(),
            SyncActions::confirmImport(),
            SyncActions::cancelImport(),
        ];
    }

    public function getTabs(): array
    {
        $today = Carbon::today()->toDateString();

        $counts = AuthorizedReportScope::todayCounts(auth()->user());

        return [
            'all' => Tab::make(__('ui.all'))
                ->badgeIcon('heroicon-o-rectangle-stack')
                ->modifyQueryUsing(fn ($query) => $query),

            'checked' => Tab::make(__('ui.checked'))
                ->badge($counts['checked'])
                ->badgeColor('success')
                ->badgeIcon('heroicon-o-finger-print')
                ->modifyQueryUsing(fn ($query) =>
                $query->whereDate('date', $today)->whereNotNull('first_reading')
                ),

            'not_checked' => Tab::make(__('ui.not_checked'))
                ->badge($counts['not_checked'])
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