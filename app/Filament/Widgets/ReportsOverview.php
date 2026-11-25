<?php

namespace App\Filament\Widgets;

use App\Models\Scopes\AuthorizedReportScope;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ReportsOverview extends BaseWidget
{
    protected function getHeading(): ?string
    {
        return __('ui.cart_reading_reports');
    }

    protected function getStats(): array
    {
        $counts = AuthorizedReportScope::todayCounts(auth()->user());
        $percent = $counts['expected'] > 0 ? round($counts['checked'] * 100 / $counts['expected']) : 0;

        return [
            Stat::make(__('ui.expected_today'), $counts['expected'])
                ->icon('heroicon-o-identification')
                ->description(__('ui.active_employees'))
                ->descriptionIcon('heroicon-o-user-group')
                ->descriptionColor('primary'),

            Stat::make(__('ui.checked'), $counts['checked'])
                ->icon('heroicon-o-identification')
                ->description(__('ui.checked_percent', ['percent' => $percent]))
                ->descriptionIcon('heroicon-o-finger-print')
                ->descriptionColor('success'),

            Stat::make(__('ui.not_checked'), $counts['not_checked'])
                ->icon('heroicon-o-identification')
                ->description(__('ui.not_checked_hint'))
                ->descriptionIcon('heroicon-o-no-symbol')
                ->descriptionColor('warning'),
        ];
    }
}
