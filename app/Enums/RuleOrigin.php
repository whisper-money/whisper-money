<?php

namespace App\Enums;

enum RuleOrigin: string
{
    case User = 'user';
    case Ai = 'ai';
    case Correction = 'correction';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Ai => 'AI',
            self::Correction => 'Correction',
        };
    }

    public function isAi(): bool
    {
        return $this === self::Ai;
    }
}
