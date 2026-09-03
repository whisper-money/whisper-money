<?php

namespace App\Services\Achievements;

/**
 * A medal the evaluator found in a user's history: which one, the month it
 * happened, and the figure that earned it.
 *
 * The named constructors are the only way to build one, so the figure and the
 * column it lands in can never disagree.
 */
final readonly class Unlock
{
    private function __construct(
        public string $month,
        private ?int $value = null,
        private ?float $percent = null,
        private ?string $currency = null,
    ) {}

    /**
     * @param  int  $amount  minor units of $currency
     */
    public static function money(string $month, int $amount, string $currency): self
    {
        return new self($month, value: $amount, currency: $currency);
    }

    /**
     * A count of transactions, or of months in a row.
     */
    public static function count(string $month, int $count): self
    {
        return new self($month, value: $count);
    }

    public static function rate(string $month, float $percent): self
    {
        return new self($month, percent: $percent);
    }

    /**
     * Something that happened, with no figure to it: a first bank connected, a
     * loan paid off.
     */
    public static function event(string $month): self
    {
        return new self($month);
    }

    /**
     * @return array<string, mixed> the columns of an `achievements` row
     */
    public function attributes(): array
    {
        return [
            'achieved_on' => $this->month.'-01',
            'value' => $this->value,
            'percent' => $this->percent,
            'currency_code' => $this->currency,
        ];
    }
}
