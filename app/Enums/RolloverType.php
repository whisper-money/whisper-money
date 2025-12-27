<?php

namespace App\Enums;

enum RolloverType: string
{
    case CarryOver = 'carry_over';
    case Reset = 'reset';

    public function label(): string
    {
        return match ($this) {
            self::CarryOver => 'Carry Over',
            self::Reset => 'Reset/Pool',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::CarryOver => 'Remaining balance carries over to next period',
            self::Reset => 'Remaining balance returns to available money pool',
        };
    }
}
