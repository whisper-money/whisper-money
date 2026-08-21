<?php

namespace App\Console\Commands;

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Banking\TransactionFingerprint;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * One-off cleanup for the N26 duplicates that landed before
 * TransactionFingerprint stopped keying N26 on its per-delivery
 * `entry_reference` and on the `bank_transaction_code` that mutates on
 * settlement.
 *
 * Two jobs, one pass over each N26 account:
 *  - soft-delete the redundant copy of every transaction that imported twice;
 *  - realign the surviving row's `dedup_fingerprint` on the value the fixed
 *    code now produces, so the next sync recognises it instead of importing a
 *    third copy. Soft-delete (not hard) is what stops a removed row coming
 *    back: the dedup preload reads `withTrashed()`.
 */
class DedupeN26Transactions extends Command
{
    protected $signature = 'banking:dedupe-n26-transactions
        {--apply : Write the changes. Without it the command only reports what it would do}
        {--user= : Filter by user email address}';

    protected $description = 'Soft-delete the N26 transactions duplicated by its per-delivery entry_reference and realign the survivors on the fixed dedup fingerprint';

    private const string BANK_NAME = 'N26';

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
            ->whereHas('bank', fn ($query) => $query->where('name', self::BANK_NAME))
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->with('bank', 'user')
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
        $key = $account->user->email;

        // withTrashed: a fingerprint already held by a soft-deleted twin still
        // occupies the (account_id, dedup_fingerprint) unique index, and still
        // dedupes incoming syncs, so the group has to see those rows too.
        $rows = Transaction::withTrashed()
            ->where('account_id', $account->id)
            ->where('source', TransactionSource::EnableBanking)
            ->with('labels')
            ->orderBy('created_at')
            ->get()
            // No payload, no content to fingerprint — hashing an empty array
            // would lump every such row into one group and delete real rows.
            ->filter(fn (Transaction $transaction): bool => filled($transaction->raw_data));

        foreach ($rows->groupBy(fn (Transaction $t): string => TransactionFingerprint::for($t->raw_data, $bankName)) as $fingerprint => $group) {
            $this->dedupeGroup((string) $fingerprint, $group, $apply, $report, $key);
        }
    }

    /**
     * @param  Collection<int, Transaction>  $group
     * @param  array<string, array<string, int>>  $report
     */
    private function dedupeGroup(string $fingerprint, Collection $group, bool $apply, array &$report, string $key): void
    {
        $live = $group->reject(fn (Transaction $transaction): bool => $transaction->trashed());
        $survivor = $this->resolveSurvivor($live);

        if ($survivor === null) {
            if ($live->count() > 1) {
                $this->tally($report, $key, 'skipped', $live->count() - 1);
                $this->line("  {$key}: left {$live->count()} copies of \"{$live->first()->description}\" alone — more than one carries notes or labels");
            }

            return;
        }

        foreach ($live as $duplicate) {
            if ($duplicate->is($survivor)) {
                continue;
            }

            $this->tally($report, $key, 'deleted', 1);

            if ($this->output->isVerbose()) {
                $this->line("  {$key}: deleting duplicate of \"{$duplicate->description}\" ({$duplicate->transaction_date->toDateString()})");
            }

            if ($apply) {
                // Not quietly: the TransactionDeleted listener takes the
                // duplicate back out of the budget it was double-counting in.
                $duplicate->delete();
            }
        }

        // Only ever written when no row in the group holds the value yet, so
        // the unique index cannot be violated.
        if ($group->every(fn (Transaction $transaction): bool => $transaction->dedup_fingerprint !== $fingerprint)) {
            $this->tally($report, $key, 'realigned', 1);

            if ($apply) {
                // Quietly: dedup metadata is invisible to the user and to every
                // TransactionUpdated listener.
                $survivor->updateQuietly(['dedup_fingerprint' => $fingerprint]);
            }
        }
    }

    /**
     * The copy to keep: the one carrying notes or labels, else the one the user
     * categorized by hand, else the one imported first. Null when two copies
     * both carry notes or labels — that text cannot be reconstructed from the
     * survivor, so merging them is a judgement call this command does not get
     * to make. A manual category is not grounds to bail: the copies are the
     * same transaction, so the survivor's own category says the same thing.
     *
     * @param  Collection<int, Transaction>  $live
     */
    private function resolveSurvivor(Collection $live): ?Transaction
    {
        $irreplaceable = $live->filter(fn (Transaction $transaction): bool => $this->carriesOwnText($transaction));

        if ($irreplaceable->count() > 1) {
            return null;
        }

        return $irreplaceable->first()
            ?? $live->first(fn (Transaction $transaction): bool => $transaction->category_source === CategorySource::Manual)
            ?? $live->first();
    }

    private function carriesOwnText(Transaction $transaction): bool
    {
        return filled($transaction->notes) || $transaction->labels->isNotEmpty();
    }

    /**
     * @param  array<string, array<string, int>>  $report
     */
    private function tally(array &$report, string $key, string $metric, int $count): void
    {
        $report[$key] ??= ['deleted' => 0, 'realigned' => 0, 'skipped' => 0];
        $report[$key][$metric] += $count;
    }

    /**
     * @param  array<string, array<string, int>>  $report
     */
    private function renderReport(array $report, bool $apply): int
    {
        if ($report === []) {
            $this->info('No N26 duplicates found.');

            return self::SUCCESS;
        }

        ksort($report);

        $this->table(
            ['User', 'Duplicates', 'Fingerprints realigned', 'Left alone (user data)'],
            array_map(
                fn (string $key, array $counts): array => [$key, $counts['deleted'], $counts['realigned'], $counts['skipped']],
                array_keys($report),
                $report,
            ),
        );

        $deleted = array_sum(array_column($report, 'deleted'));
        $realigned = array_sum(array_column($report, 'realigned'));
        $verb = $apply ? 'soft-deleted' : 'would be soft-deleted';

        $this->info("{$deleted} duplicate(s) {$verb} across ".count($report).' user(s); '."{$realigned} fingerprint(s) realigned.");

        return self::SUCCESS;
    }
}
