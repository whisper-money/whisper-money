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
        $plan = $undo ? $this->undoable($this->converter->undoPlan($userId)) : $this->converter->plan($userId);

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

    /**
     * The converted splits an undo can put back: those whose live parts still
     * match their lines. A split whose parts were edited, relabelled or
     * deleted since is left as it is and listed, so the rest can go back.
     */
    private function undoable(LegacySplitPlan $plan): LegacySplitPlan
    {
        $changed = $this->verifier->partsAgainstLines($plan->originals);

        if ($changed === []) {
            return $plan;
        }

        return new LegacySplitPlan(
            $plan->originals->reject(fn (Transaction $original): bool => isset($changed[$original->id]))->values(),
            [LegacySplitConverter::SKIP_CHANGED => array_map('strval', array_keys($changed))],
        );
    }

    private function verified(LegacySplitPlan $plan, bool $undo): LegacySplitReport
    {
        $months = $plan->monthsByUser();
        // Fixed before anything moves: the periods holding a row of what the
        // run takes rows off, whoever owns their budget.
        $periods = $this->snapshot->budgetPeriodsHolding($this->budgetRowHolders($plan, $undo));
        $before = $this->snapshot->take($months, $periods);
        [$parts, $moved, $fallback] = $undo ? $this->undoAll($plan) : $this->convertAll($plan);
        $comparison = $this->verifier->compare($before, $this->snapshot->take($months, $periods), $plan->foreignCurrencyParts());
        $failures = $undo ? $this->verifier->restored($plan->originals) : $this->verifier->partsAgainstLines($plan->originals);

        return new LegacySplitReport($plan, $undo, $parts, $moved, $fallback, $comparison['differences'], $comparison['fx_rounding'], $failures);
    }

    /**
     * The transactions whose budget rows the run moves: the originals for a
     * conversion, their live parts for an undo.
     *
     * @return list<string>
     */
    private function budgetRowHolders(LegacySplitPlan $plan, bool $undo): array
    {
        $originalIds = $plan->originals->modelKeys();

        return $undo
            ? Transaction::query()->whereIn('split_parent_id', $originalIds)->pluck('id')->all()
            : array_values($originalIds);
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
