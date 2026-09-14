<?php

namespace App\Services\Stats;

/**
 * One arm of a binomial experiment: a count of successes out of trials, with a
 * label. Bundles the (successes, trials) pair that the significance methods
 * would otherwise pass around as loose integers.
 */
final readonly class BinomialProportion
{
    public function __construct(
        public string $label,
        public int $successes,
        public int $trials,
    ) {}

    public function rate(): float
    {
        return $this->trials > 0 ? (float) $this->successes / $this->trials : 0.0;
    }
}
