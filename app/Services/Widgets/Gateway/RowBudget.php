<?php

namespace App\Services\Widgets\Gateway;

/**
 * How many rows one resolution may still read through `paginate: "all"`
 * calls. Each of them is capped by `widgets.gateway.max_rows` on its own, and
 * a manifest may declare many of them (a dashboard resolves every widget's in
 * one go), so their sum is capped too: every row read is held in memory until
 * the answer is sent.
 */
final class RowBudget
{
    private int $remaining;

    public function __construct(public readonly int $total)
    {
        $this->remaining = max(0, $total);
    }

    public function remaining(): int
    {
        return $this->remaining;
    }

    public function isSpent(): bool
    {
        return $this->remaining === 0;
    }

    public function spend(int $rows): void
    {
        $this->remaining = max(0, $this->remaining - $rows);
    }
}
