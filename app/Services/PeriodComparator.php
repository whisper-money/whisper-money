<?php

namespace App\Services;

use Carbon\Carbon;

class PeriodComparator
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to
    ) {}

    /**
     * Calendar days in the range, both ends included.
     */
    public function days(): int
    {
        return (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1;
    }

    public function previous(): self
    {
        $days = $this->from->diffInDays($this->to) + 1;

        // If it's a full month range (e.g. 1st to end of month), shift by months
        if ($this->isFullMonthRange()) {
            $months = $this->from->diffInMonths($this->to->copy()->addDay());

            $previousFrom = $this->from->copy()->subMonthsNoOverflow($months);
            $previousTo = $previousFrom->copy()->addMonthsNoOverflow($months - 1)->endOfMonth();

            return new self($previousFrom, $previousTo);
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

    /**
     * The validation rules of a `from`/`to` range, both required dates. The
     * analytics endpoints, MCP tools and public API all take their range
     * through these.
     *
     * @return array{from: list<string>, to: list<string>}
     */
    public static function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ];
    }

    public static function fromRequest(array $validated): self
    {
        return new self(
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to'])
        );
    }
}
