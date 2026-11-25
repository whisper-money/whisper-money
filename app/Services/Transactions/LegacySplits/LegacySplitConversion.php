<?php

namespace App\Services\Transactions\LegacySplits;

use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One run of the legacy split conversion, or of its undo, inside a single
 * database transaction: snapshot, convert every original, snapshot again,
 * verify. The transaction is committed only when asked to and when nothing
 * that must stay equal changed; a preview, a failed verification or an
 * error rolls all of it back, so a transaction is never left half converted.
 *
 * It holds every original of the run under one transaction, so it is meant
 * to run once, while nobody is writing.
 */
class LegacySplitConversion
{
    public function __construct(
        private readonly LegacySplitConverter $converter,
        private readonly LegacySplitSnapshot $snapshot,
        private readonly LegacySplitVerifier $verifier,
    ) {}

    /**
     * @param  bool  $execute  commit when the verification passes; otherwise always roll back
     */
    public function run(?string $userId, bool $undo, bool $execute): LegacySplitReport
    {
        $plan = $undo ? $this->converter->undoPlan($userId) : $this->converter->plan($userId);

        if ($plan->originals->isEmpty()) {
            return new LegacySplitReport($plan, $undo);
        }

        DB::beginTransaction();

        try {
            $report = $this->verified($plan, $undo);
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        if (! $execute || ! $report->passed()) {
            DB::rollBack();

            return $report;
        }

        DB::commit();

        return new LegacySplitReport($plan, $undo, $report->parts, $report->budgetRowsMoved, $report->fallback, $report->differences, $report->fxRounding, $report->originalFailures, written: true);
    }

    private function verified(LegacySplitPlan $plan, bool $undo): LegacySplitReport
    {
        $months = $plan->monthsByUser();
        $before = $this->snapshot->take($months);
        [$parts, $moved, $fallback] = $undo ? $this->undoAll($plan) : $this->convertAll($plan);
        $comparison = $this->verifier->compare($before, $this->snapshot->take($months), $plan->foreignCurrencyParts());
        $failures = $undo ? $this->verifier->restored($plan->originals) : $this->verifier->partsAgainstLines($plan->originals);

        return new LegacySplitReport($plan, $undo, $parts, $moved, $fallback, $comparison['differences'], $comparison['fx_rounding'], $failures);
    }

    /**
     * @return array{0: int, 1: int, 2: list<array{transaction_id: string, budget_period_id: string}>}
     */
    private function convertAll(LegacySplitPlan $plan): array
    {
        $parts = 0;
        $moved = 0;
        $fallback = [];

        foreach ($plan->originals as $original) {
            $converted = $this->converter->convert($original);
            $parts += $converted['parts'];
            $moved += $converted['moved'];
            array_push($fallback, ...$converted['fallback']);
        }

        return [$parts, $moved, $fallback];
    }

    /**
     * @return array{0: int, 1: int, 2: list<array{transaction_id: string, budget_period_id: string}>}
     */
    private function undoAll(LegacySplitPlan $plan): array
    {
        $parts = Transaction::query()->whereIn('split_parent_id', $plan->originals->modelKeys())->count();
        $moved = $plan->originals->sum(fn (Transaction $original): int => $this->converter->undo($original));

        return [$parts, $moved, []];
    }
}
