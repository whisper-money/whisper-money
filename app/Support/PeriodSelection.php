<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The calendar period a page is looking at, read from its `?period_type=` and
 * `?period=` query (`month` + `2026-11`, `quarter` + `2026-Q4`, `year` +
 * `2026`). A value that is missing or does not match its type is dropped, not
 * rejected: the page then opens on the period in progress.
 */
readonly class PeriodSelection
{
    public const TYPES = ['month', 'quarter', 'year'];

    public function __construct(
        public string $type,
        public ?string $period,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $type = $request->query('period_type');
        $validType = is_string($type) && in_array($type, self::TYPES, true) ? $type : 'month';

        return new self($validType, self::validPeriod($request->query('period'), $validType));
    }

    /**
     * The first day of the period, or of the one in progress on `$today` when
     * none was given.
     */
    public function start(CarbonImmutable $today): CarbonImmutable
    {
        if ($this->period === null) {
            return $this->startOfPeriodContaining($today);
        }

        $year = (int) substr($this->period, 0, 4);

        return match ($this->type) {
            'quarter' => CarbonImmutable::create($year, ((int) substr($this->period, -1) - 1) * 3 + 1, 1, 0, 0, 0, $today->getTimezone()),
            'year' => CarbonImmutable::create($year, 1, 1, 0, 0, 0, $today->getTimezone()),
            default => CarbonImmutable::create($year, (int) substr($this->period, 5, 2), 1, 0, 0, 0, $today->getTimezone()),
        };
    }

    /**
     * The `?period=` value naming the period that starts on `$start`.
     */
    public function param(CarbonImmutable $start): string
    {
        return match ($this->type) {
            'quarter' => $start->format('Y').'-Q'.$start->quarter,
            'year' => $start->format('Y'),
            default => $start->format('Y-m'),
        };
    }

    public function end(CarbonImmutable $start): CarbonImmutable
    {
        return $this->next($start)->subDay();
    }

    public function previous(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this->type) {
            'quarter' => $start->subQuarterNoOverflow(),
            'year' => $start->subYearNoOverflow(),
            default => $start->subMonthNoOverflow(),
        };
    }

    private function next(CarbonImmutable $start): CarbonImmutable
    {
        return match ($this->type) {
            'quarter' => $start->addQuarterNoOverflow(),
            'year' => $start->addYearNoOverflow(),
            default => $start->addMonthNoOverflow(),
        };
    }

    private function startOfPeriodContaining(CarbonImmutable $day): CarbonImmutable
    {
        return match ($this->type) {
            'quarter' => $day->startOfQuarter(),
            'year' => $day->startOfYear(),
            default => $day->startOfMonth(),
        };
    }

    private static function validPeriod(mixed $period, string $type): ?string
    {
        if (! is_string($period)) {
            return null;
        }

        $pattern = match ($type) {
            'quarter' => '/^\d{4}-Q[1-4]$/',
            'year' => '/^\d{4}$/',
            default => '/^\d{4}-(0[1-9]|1[0-2])$/',
        };

        return preg_match($pattern, $period) === 1 ? $period : null;
    }
}
