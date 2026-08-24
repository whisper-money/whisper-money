<?php

namespace App\Enums;

enum CategorySource: string
{
    case Manual = 'manual';
    case Rule = 'rule';
    case Ai = 'ai';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Rule => 'Rule',
            self::Ai => 'AI',
            self::Bank => 'Bank',
        };
    }

    public function isAi(): bool
    {
        return $this === self::Ai;
    }
}
