<?php

namespace App\Services\Transactions\LegacySplits;

use App\Services\Budgets\SplitBudgetRows;

/**
 * What a run of the legacy split conversion did, or would do, and whether
 * its verification passed.
 *
 * @phpstan-import-type FallbackRow from SplitBudgetRows
 * @phpstan-import-type Difference from LegacySplitVerifier
 */
class LegacySplitReport
{
    /**
     * @param  int  $parts  parts made, or removed by an undo
     * @param  int  $budgetRowsMoved  budget rows taken off one side and shared on the other
     * @param  list<FallbackRow>  $fallback  original rows shared by part amount, see {@see SplitBudgetRows::toParts()}
     * @param  list<Difference>  $differences  figures that changed
     * @param  list<Difference>  $fxRounding  figures that moved within the FX rounding accepted
     * @param  array<string, string>  $originalFailures  why an original does not match its lines, by id
     * @param  bool  $written  whether the run was committed
     */
    public function __construct(
        public readonly LegacySplitPlan $plan,
        public readonly bool $undo,
        public readonly int $parts = 0,
        public readonly int $budgetRowsMoved = 0,
        public readonly array $fallback = [],
        public readonly array $differences = [],
        public readonly array $fxRounding = [],
        public readonly array $originalFailures = [],
        public readonly bool $written = false,
    ) {}

    public function passed(): bool
    {
        return $this->differences === [] && $this->originalFailures === [];
    }
}
