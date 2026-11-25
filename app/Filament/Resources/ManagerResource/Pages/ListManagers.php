<?php

namespace App\Filament\Resources\ManagerResource\Pages;

use App\Enums\ManagerStatusEnum;
use App\Filament\Resources\ManagerResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListManagers extends ListRecords
{
    protected static string $resource = ManagerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        $tabs = [];
        $count = fn (?ManagerStatusEnum $status = null): int => ManagerResource::getEloquentQuery()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->count();

        $tabs['all'] = Tab::make(__('ui.all'))
            ->badge($count());

        $tabs['active'] = Tab::make(__('ui.active'))
            ->badge($count(ManagerStatusEnum::ACTIVE))
            ->badgeIcon('heroicon-o-check-circle')
            ->badgeColor('success')
            ->modifyQueryUsing(fn ($query) => $query->where('status', ManagerStatusEnum::ACTIVE));

        $tabs['inactive'] = Tab::make(__('ui.inactive'))
            ->badge($count(ManagerStatusEnum::INACTIVE))
            ->badgeIcon('heroicon-o-x-circle')
            ->badgeColor('danger')
            ->modifyQueryUsing(fn ($query) => $query->where('status', ManagerStatusEnum::INACTIVE));

        return $tabs;

    }
}
