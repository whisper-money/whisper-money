<?php

namespace App\Services;

use Carbon\Carbon;

class PeriodComparator
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to
    ) {}

    public function previous(): self
    {
        $days = $this->from->diffInDays($this->to) + 1;

        // If it's a full month range (e.g. 1st to end of month), shift by months
        if ($this->isFullMonthRange()) {
            $months = $this->from->diffInMonths($this->to->copy()->addDay());

            return new self(
                $this->from->copy()->subMonths($months),
                $this->to->copy()->subMonths($months)->endOfMonth()
            );
        }

        return new self(
            $this->from->copy()->subDays($days),
            $this->from->copy()->subDay()
        );
    }

    private function isFullMonthRange(): bool
    {
        return $this->from->day === 1 &&
               $this->to->isLastOfMonth();
    }

    public static function fromRequest(array $validated): self
    {
        return new self(
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to'])
        );
    }
}
