<?php

namespace App\Filament\Widgets;

use App\Enums\ManagerStatusEnum;
use App\Models\Report;
use App\Models\Scopes\AuthorizedReportScope;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

class ReportsOverview extends BaseWidget
{
    protected function getHeading(): ?string
    {
        return __('ui.cart_reading_reports');
    }

    protected function getStats(): array
    {
        $today = Carbon::today()->toDateString();

        $reportQuery = AuthorizedReportScope::apply(Report::query(), auth()->user());

        $todayReports    = (clone $reportQuery)->whereDate('date', $today);
        $allCount        = (clone $reportQuery)->count();
        $checkedCount    = (clone $todayReports)->whereNotNull('first_reading')->where('status', ManagerStatusEnum::ACTIVE)->count();
        $notCheckedCount = (clone $todayReports)->whereNull('first_reading')->whereNull('last_reading')->where('status', ManagerStatusEnum::ACTIVE)->count();

        return [
            Stat::make(__('ui.all'), $allCount)
                ->icon('heroicon-o-identification')
                ->descriptionColor('primary'),

            Stat::make(__('ui.daily_report'), $checkedCount)
                ->icon('heroicon-o-identification')
                ->description(__('ui.checked'))
                ->descriptionIcon('heroicon-o-finger-print')
                ->descriptionColor('success'),

            Stat::make(__('ui.daily_report'), $notCheckedCount)
                ->icon('heroicon-o-identification')
                ->description(__('ui.not_checked'))
                ->descriptionIcon('heroicon-o-no-symbol')
                ->descriptionColor('warning'),
        ];
    }
}