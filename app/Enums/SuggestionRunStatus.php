<?php

namespace App\Enums;

enum SuggestionRunStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Empty = 'empty';
    case Failed = 'failed';

    /**
     * Whether this run produced usable suggestions and should count against
     * the monthly throttle.
     */
    public function countsTowardThrottle(): bool
    {
        return $this === self::Completed;
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Empty, self::Failed], true);
    }
}
