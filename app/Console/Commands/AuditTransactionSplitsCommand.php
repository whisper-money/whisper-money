<?php

namespace App\Console\Commands;

use App\Enums\CategoryType;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AuditTransactionSplitsCommand extends Command
{
    /**
     * Ids per anomaly code kept in the logged breakdown. Enough to start
     * investigating without shipping an unbounded id dump to the log sink.
     */
    private const int LOGGED_IDS_PER_CODE = 10;

    protected $signature = 'transactions:audit-splits {--json : Emit stable JSON} {--fail-on-invalid : Return failure when anomalies exist}';

    protected $description = 'Read-only integrity audit of transaction splits';

    public function handle(): int
    {
        $report = $this->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Reviewed {$report['parents_reviewed']} parents and {$report['lines_reviewed']} lines; {$report['anomaly_count']} anomalies.");
            foreach ($report['anomalies'] as $anomaly) {
                $this->line($anomaly['code'].' '.$anomaly['id']);
            }
        }

        if ($report['anomaly_count'] > 0) {
            $this->logAnomalies($report);
        }

        return $this->option('fail-on-invalid') && $report['anomaly_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Every transaction with lines, live or trashed, and every orphan line.
     * Given a user, only that user's transactions and their lines: an orphan
     * line has no owner left, so it is only looked for without one.
     *
     * @return array{parents_reviewed: int, lines_reviewed: int, anomaly_count: int, anomalies: list<array{code: string, id: string}>}
     */
    public function audit(?string $userId = null): array
    {
        $anomalies = [];
        $parentsReviewed = 0;
        $linesReviewed = (int) DB::table('transaction_splits')
            ->when($userId !== null, fn (QueryBuilder $lines) => $lines->whereIn('transaction_id', DB::table('transactions')->where('user_id', $userId)->select('id')))
            ->count();

        Transaction::query()
            ->withTrashed()
            ->whereHas('splits')
            ->when($userId !== null, fn (Builder $transactions) => $transactions->where('user_id', $userId))
            ->with(['splits', 'splits.category' => fn ($query) => $query->withTrashed()])
            ->chunkById(200, function ($transactions) use (&$anomalies, &$parentsReviewed): void {
                foreach ($transactions as $transaction) {
                    $parentsReviewed++;
                    $this->checkParent($transaction, $anomalies);
                }
            });

        $orphanedLineIds = $userId === null ? $this->orphanedLineIds() : collect();

        foreach ($orphanedLineIds as $orphanedLineId) {
            $this->check(true, 'parent_missing', (string) $orphanedLineId, $anomalies);
        }

        usort($anomalies, fn (array $left, array $right): int => [$left['code'], $left['id']] <=> [$right['code'], $right['id']]);

        return [
            'parents_reviewed' => $parentsReviewed,
            'lines_reviewed' => $linesReviewed,
            'anomaly_count' => count($anomalies),
            'anomalies' => $anomalies,
        ];
    }

    /**
     * The anomalies of one transaction and its lines.
     *
     * @param  list<array{code: string, id: string}>  $anomalies
     */
    private function checkParent(Transaction $transaction, array &$anomalies): void
    {
        $splits = $transaction->splits;
        $this->check(count($splits) < 2, 'parent_has_fewer_than_two_lines', $transaction->id, $anomalies);
        $this->check((int) $splits->sum('amount') !== $transaction->amount, 'split_sum_mismatch', $transaction->id, $anomalies);
        $this->check($splits->contains(fn ($split): bool => $split->amount === 0), 'split_amount_zero', $transaction->id, $anomalies);
        $sign = $transaction->amount <=> 0;
        $this->check($sign === 0 || $splits->contains(fn ($split): bool => ($split->amount <=> 0) !== $sign), 'split_sign_mismatch', $transaction->id, $anomalies);

        $positions = $splits->pluck('position')->sort()->values()->all();
        $this->check($positions !== range(0, max(count($positions) - 1, 0)), 'position_not_contiguous', $transaction->id, $anomalies);

        $missing = $splits->contains(fn ($split): bool => $split->category === null || $split->category->trashed());
        $this->check($missing, 'category_missing_or_deleted', $transaction->id, $anomalies);
        $this->check($splits->contains(fn ($split): bool => $split->category !== null && ($split->category->user_id !== $transaction->user_id || $split->category->space_id !== $transaction->space_id)), 'category_owner_or_space_mismatch', $transaction->id, $anomalies);

        $types = $splits->map(fn ($split): ?string => $split->category?->type?->value)->filter()->unique();
        $validTypes = [CategoryType::Expense->value, CategoryType::Income->value];
        $this->check($types->count() !== 1 || ! in_array($types->first(), $validTypes, true), 'category_type_invalid_or_mixed', $transaction->id, $anomalies);

        $this->check(self::hasClassification($transaction), 'parent_classification_present', $transaction->id, $anomalies);
    }

    /**
     * Whether a transaction with lines still carries a category of its own,
     * or what put one there.
     */
    private static function hasClassification(Transaction $transaction): bool
    {
        return $transaction->category_id !== null
            || $transaction->category_source !== null
            || $transaction->categorized_by_rule_id !== null
            || $transaction->ai_suggested_category_id !== null
            || $transaction->ai_suggested_category_at !== null
            || $transaction->ai_model !== null
            || $transaction->ai_confidence !== null;
    }

    /**
     * Lines whose transaction is gone for good.
     *
     * @return Collection<int, mixed>
     */
    private function orphanedLineIds(): Collection
    {
        return DB::table('transaction_splits as split')
            ->leftJoin('transactions as parent', 'parent.id', '=', 'split.transaction_id')
            ->whereNull('parent.id')
            ->orderBy('split.id')
            ->pluck('split.id');
    }

    /**
     * A scheduled run discards stdout, so an audit that finds anomalies reaches
     * the error tracker as a bare "exit code [1]" with nothing to act on. Log
     * the breakdown so the alert carries what broke and where to look. Only
     * anomaly codes, counts and ids travel — no amounts, descriptions or owners.
     *
     * @param  array{parents_reviewed: int, lines_reviewed: int, anomaly_count: int, anomalies: list<array{code: string, id: string}>}  $report
     */
    private function logAnomalies(array $report): void
    {
        $byCode = collect($report['anomalies'])->groupBy('code');

        Log::error('Transaction split audit found anomalies', [
            'parents_reviewed' => $report['parents_reviewed'],
            'lines_reviewed' => $report['lines_reviewed'],
            'anomaly_count' => $report['anomaly_count'],
            'counts_by_code' => $byCode->map(fn (Collection $anomalies): int => $anomalies->count())->all(),
            'ids_by_code' => $byCode
                ->map(fn (Collection $anomalies): array => $anomalies->pluck('id')->take(self::LOGGED_IDS_PER_CODE)->values()->all())
                ->all(),
        ]);
    }

    /** @param list<array{code: string, id: string}> $anomalies */
    private function check(bool $invalid, string $code, string $id, array &$anomalies): void
    {
        if ($invalid) {
            $anomalies[] = ['code' => $code, 'id' => $id];
        }
    }
}
