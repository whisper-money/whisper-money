<?php

namespace App\Enums;

enum CategoryCashflowDirection: string
{
    case Hidden = 'hidden';
    case Inflow = 'inflow';
    case Outflow = 'outflow';

    public function label(): string
    {
        return match ($this) {
            self::Hidden => 'Do not show',
            self::Inflow => 'Show as cash inflow',
            self::Outflow => 'Show as cash outflow',
        };
    }
}
