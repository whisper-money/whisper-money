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

    /**
     * Rules the AI categorizer is allowed to mutate or self-heal. A user's own
     * rules are sacred; a correction the user made is sacred too.
     */
    public function isManagedByAi(): bool
    {
        return $this === self::Ai;
    }
}
