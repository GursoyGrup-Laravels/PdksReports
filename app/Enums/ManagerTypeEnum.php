<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ManagerTypeEnum: int implements HasLabel, HasColor
{
    case MANAGER = 0;
    case HR = 1;

    public function getLabel(): string
    {
        return match ($this) {
            self::MANAGER => __('ui.manager'),
            self::HR      => __('ui.hr'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MANAGER => 'primary',
            self::HR      => 'warning',
        };
    }
}
