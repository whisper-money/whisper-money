<?php

namespace App\Enums;

enum RuleOrigin: string
{
    case User = 'user';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::User => 'User',
            self::Ai => 'AI',
        };
    }

    public function isAi(): bool
    {
        return $this === self::Ai;
    }
}
