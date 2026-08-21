<?php

namespace App\Console\Commands;

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\TransactionFingerprint;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup for the duplicates that landed while TransactionFingerprint
 * still keyed N26 on a reference it mints per delivery.
 *
 * Two jobs, one pass over each account of a bank in
 * TransactionFingerprint::unstableIdBanks():
 *
 *  - soft-delete the copies the bank re-delivered on a later sync, keeping as
 *    many rows as it stated in one response;
 *  - realign one row per group on the fingerprint the fixed code now produces.
 *    Changing the formula orphans every value already stored, so without this
 *    the next sync would not recognise its own rows. Every group gets realigned,
 *    including a group whose rows are all soft-deleted — otherwise a deleted
 *    transaction comes back on the next sync. Soft-delete (not hard) is what
 *    makes that work at all: the dedup preload reads `withTrashed()` (#114).
 */
class DedupeN26Transactions extends Command
{
    protected $signature = 'banking:dedupe-n26-transactions
        {--apply : Write the changes. Without it the command only reports what it would do}
        {--user= : Filter by user email address}';

    protected $description = 'Soft-delete the N26 transactions duplicated by its per-delivery entry_reference and realign the survivors on the fixed dedup fingerprint';

    /**
     * How far apart two rows can be imported and still count as the same sync
     * run. Copies inside one run are the bank stating a multiplicity — 30
     * identical ad-platform charges in one response are 30 real charges — and
     * are kept. Copies that turn up in a later run are re-deliveries of what we
     * already hold, and syncs are six hours apart.
     */
    private const int SAME_SYNC_RUN_MINUTES = 10;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $userEmail = $this->option('user');
        $userId = null;

        if (! $apply) {
            $this->warn('DRY RUN — no changes will be saved. Pass --apply to write.');
        }

        if ($userEmail) {
            $user = User::query()->where('email', $userEmail)->first();

            if (! $user) {
                $this->error("User with email '{$userEmail}' not found.");

                return self::FAILURE;
            }

            $userId = $user->id;
        }

        /** @var array<string, array<string, int>> $report */
        $report = [];

        Account::query()
            ->whereHas('bank', fn (Builder $query) => $query->whereIn(DB::raw('lower(banks.name)'), TransactionFingerprint::unstableIdBanks()))
            ->when($userId, fn (Builder $query) => $query->where('user_id', $userId))
            ->with('bank')
            ->chunkById(50, function ($accounts) use ($apply, &$report): void {
                foreach ($accounts as $account) {
                    $this->dedupeAccount($account, $apply, $report);
                }
            });

        return $this->renderReport($report, $apply);
    }

    /**
     * @param  array<string, array<string, int>>  $report
     */
    private function dedupeAccount(Account $account, bool $apply, array &$report): void
    {
        $bankName = $account->bank?->name;

        // withTrashed: a fingerprint held by a soft-deleted row still occupies
        // the (account_id, dedup_fingerprint) unique index and still dedupes
        // incoming syncs, so the group has to see those rows too.
        //
        // Ordered by id as well as created_at: created_at has second precision
        // and most groups were imported inside one second, so without the
        // tiebreak the dry run could name a different survivor than --apply.
        $rows = Transaction::withTrashed()
            ->where('account_id', $account->id)
            ->where('source', TransactionSource::EnableBanking)
            ->with('labels')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            // No payload, no content to fingerprint — hashing an empty array
            // would lump every such row into one group and delete real rows.
            ->filter(fn (Transaction $transaction): bool => filled($transaction->raw_data));

        foreach ($rows->groupBy(fn (Transaction $t): string => TransactionFingerprint::for($t->raw_data, $bankName)) as $fingerprint => $group) {
            $this->dedupeGroup((string) $fingerprint, $group, $apply, $report, $account->user_id);
        }
    }

    /**
     * @param  Collection<int, Transaction>  $group
     * @param  array<string, array<string, int>>  $report
     */
    private function dedupeGroup(string $fingerprint, Collection $group, bool $apply, array &$report, string $key): void
    {
        $live = $group->reject(fn (Transaction $transaction): bool => $transaction->trashed());
        $keep = $this->rowsToKeep($live);

        if ($keep === null) {
            $this->report($report, $key, 'kept', $live->count() - 1, "left {$live->count()} copies of \"{$live->first()->description}\" alone — more than one carries notes or labels");
        } else {
            $this->deleteRedundant($live, $keep, $apply, $report, $key);
        }

        // One row per group has to end up holding the recomputed fingerprint,
        // or the next sync imports the group again. Only written when no row
        // holds it yet, so the unique index cannot be violated. A group with no
        // live rows left still gets it, on a trashed row — that is what stops a
        // deleted transaction coming back.
        if ($group->every(fn (Transaction $transaction): bool => $transaction->dedup_fingerprint !== $fingerprint)) {
            $this->report($report, $key, 'realigned', 1);

            if ($apply) {
                // Quietly: dedup metadata is invisible to the user and to every
                // TransactionUpdated listener, and firing that event on
                // thousands of rows would re-run budget assignment for nothing.
                ($keep?->first() ?? $group->first())->updateQuietly(['dedup_fingerprint' => $fingerprint]);
            }
        }
    }

    /**
     * @param  Collection<int, Transaction>  $live
     * @param  Collection<int, Transaction>  $keep
     * @param  array<string, array<string, int>>  $report
     */
    private function deleteRedundant(Collection $live, Collection $keep, bool $apply, array &$report, string $key): void
    {
        $kept = $keep->map(fn (Transaction $transaction): string => $transaction->id)->all();

        if (count($kept) > 1) {
            $this->report($report, $key, 'kept', count($kept) - 1);
        }

        foreach ($live as $duplicate) {
            if (in_array($duplicate->id, $kept, true)) {
                continue;
            }

            $this->report($report, $key, 'deleted', 1, "deleting re-delivered \"{$duplicate->description}\" ({$duplicate->transaction_date->toDateString()})");

            if ($apply) {
                // Not quietly: the TransactionDeleted listener takes the
                // duplicate back out of the budget it was double-counting in.
                $duplicate->delete();
            }
        }
    }

    /**
     * The rows to keep out of a group of content-identical copies: as many as
     * the bank delivered in one response, preferring any copy the user has
     * annotated. Null when two copies both carry notes or labels — that text
     * cannot be reconstructed from the survivor, so the group is reported and
     * left whole. An empty collection when every copy is already soft-deleted.
     *
     * @param  Collection<int, Transaction>  $live
     * @return Collection<int, Transaction>|null
     */
    private function rowsToKeep(Collection $live): ?Collection
    {
        if ($live->isEmpty()) {
            return $live;
        }

        if ($live->filter(fn (Transaction $t): bool => $this->carriesOwnText($t))->count() > 1) {
            return null;
        }

        $sameRun = $live->first()->created_at->copy()->addMinutes(self::SAME_SYNC_RUN_MINUTES);
        $multiplicity = $live->filter(fn (Transaction $t): bool => $t->created_at->lte($sameRun))->count();

        // Stable sort, so within each rank the created_at/id order from the
        // query survives.
        return $live->sortByDesc(fn (Transaction $t): int => $this->userDataRank($t))
            ->take($multiplicity)
            ->values();
    }

    private function carriesOwnText(Transaction $transaction): bool
    {
        return filled($transaction->notes) || $transaction->labels->isNotEmpty();
    }

    /**
     * How much of the user's own work a copy carries, so the copy they touched
     * is the one kept. A manual category ranks below notes and labels: it can
     * be set again in one click, and the copies are the same transaction, so a
     * manual category on more than one of them is not a reason to bail.
     */
    private function userDataRank(Transaction $transaction): int
    {
        return match (true) {
            $this->carriesOwnText($transaction) => 2,
            $transaction->category_source === CategorySource::Manual => 1,
            default => 0,
        };
    }

    /**
     * @param  array<string, array<string, int>>  $report
     */
    private function report(array &$report, string $key, string $metric, int $count, ?string $line = null): void
    {
        if ($count < 1) {
            return;
        }

        $report[$key] ??= ['deleted' => 0, 'realigned' => 0, 'kept' => 0];
        $report[$key][$metric] += $count;

        // Descriptions are a user's own bank data: only on request.
        if ($line !== null && $this->output->isVerbose()) {
            $this->line("  {$key}: {$line}");
        }
    }

    /**
     * @param  array<string, array<string, int>>  $report
     */
    private function renderReport(array $report, bool $apply): int
    {
        if ($report === []) {
            $this->info('Nothing to clean up.');

            return self::SUCCESS;
        }

        // Resolved here rather than through $account->user, which is null for a
        // soft-deleted user and would take the whole run down with it.
        $emails = User::withTrashed()->whereIn('id', array_keys($report))->pluck('email', 'id');

        $this->table(
            ['User', 'Re-deliveries', 'Fingerprints realigned', 'Copies kept'],
            array_map(
                fn (string $key, array $counts): array => [$emails[$key] ?? $key, $counts['deleted'], $counts['realigned'], $counts['kept']],
                array_keys($report),
                $report,
            ),
        );

        $deleted = array_sum(array_column($report, 'deleted'));
        $realigned = array_sum(array_column($report, 'realigned'));
        $kept = array_sum(array_column($report, 'kept'));
        $verb = $apply ? 'soft-deleted' : 'would be soft-deleted';

        $this->info("{$deleted} re-delivered duplicate(s) {$verb} across ".count($report)." user(s); {$realigned} fingerprint(s) realigned; {$kept} same-response copy/copies kept.");

        return self::SUCCESS;
    }
}
