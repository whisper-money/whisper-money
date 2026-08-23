<?php

namespace App\Services\Banking\Sync;

use App\Enums\TransactionSource;
use App\Exceptions\Banking\CarriesBankingOperation;
use App\Exceptions\Banking\ExpiredBankingSessionException;
use App\Exceptions\Banking\InaccessibleBankAccountException;
use App\Exceptions\Banking\TransientBankingProviderException;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Jobs\SendDailyBankTransactionsSyncedEmailJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Services\Banking\BalanceSyncService;
use App\Services\Banking\TransactionSyncService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EnableBankingSyncer extends AbstractBankingConnectionSyncer
{
    /**
     * Days of already-synced history re-requested on every sync. Banks post
     * transactions with retroactive value dates, so starting exactly at the
     * watermark would silently miss them.
     */
    private const int WATERMARK_OVERLAP_DAYS = 3;

    /**
     * Windows reaching further back than this still ask for the 'longest'
     * strategy. Banks refuse wide unattended windows, and the narrowing retry
     * that follows would skip the history in between for good.
     */
    private const int SHORT_WINDOW_DAYS = 90;

    public function __construct(
        private TransactionSyncService $transactionSync,
        private BalanceSyncService $balanceSync,
    ) {}

    public function expires(): bool
    {
        return true;
    }

    public function notifiesOnAuthFailure(): bool
    {
        return false;
    }

    public function sync(BankingConnection $connection, bool $isFirstSync): array
    {
        $transactionsPerBank = [];
        $balanceFailed = 0;
        $balanceRateLimitFailure = null;
        $transactionFailure = null;

        $accountsAttempted = 0;

        $connection->load('accounts.bank');
        $pageBudget = $this->pageBudget($connection);

        try {
            foreach ($connection->accounts as $account) {
                $created = 0;
                $accountsAttempted++;
                [$dateFrom, $dateTo, $strategy] = $this->resolveWindow($connection, $account, $isFirstSync);

                // Read before the balance sync below writes today's row.
                $owedBackfill = $this->owesHistoricalBalances($account, $isFirstSync);

                // Balances first, and deliberately so. The transactions call is
                // the paginated one: it is what spends the consent's metered
                // daily allowance, and it is what throws. A balance call queued
                // behind it is the call that never happens - Trade Republic made
                // 5,740 transaction requests in the week to 2026-08-21 and not
                // one balance request, because every run died paginating before
                // it got here. One request per account, taken while there is
                // still allowance left to take it with.
                $balanceSynced = $this->syncBalances($connection, $account, $balanceRateLimitFailure);

                if (! $balanceSynced) {
                    $balanceFailed++;
                }

                try {
                    $created = $this->transactionSync->sync($account, $dateFrom, $dateTo, $strategy, saveDailyBalances: ! $account->isLinked(), pageBudget: $pageBudget);
                } catch (InaccessibleBankAccountException|WrongTransactionsPeriodException $e) {
                    // A single account the bank no longer exposes, or whose history
                    // window it refuses even after narrowing, must not break the
                    // whole connection sync. Skip it; the user can stop syncing it
                    // from the manage-accounts screen.
                    Log::warning('Skipping unsyncable EnableBanking account during sync', [
                        ...$connection->logContext(),
                        'account_id' => $account->id,
                        'reason' => $e::class,
                    ]);

                    continue;
                } catch (TransientBankingProviderException $e) {
                    $transactionFailure ??= $this->recordAccountTransactionFailure($connection, $account, $e);
                }

                $transactionsPerBank = $this->tally($transactionsPerBank, $account, $created);

                // Local arithmetic over the rows just imported, so unlike the
                // balance request itself this half has to follow them.
                $this->backfillHistoricalBalances($connection, $account, $owedBackfill && $balanceSynced);
            }
        } catch (\Throwable $e) {
            $this->logAbortedSync($connection, $e, [
                'accounts_attempted' => $accountsAttempted,
                'accounts_total' => $connection->accounts->count(),
                'transactions_synced' => array_sum($transactionsPerBank),
                'balance_failed' => $balanceFailed,
                'balance_rate_limited' => $balanceRateLimitFailure !== null,
            ]);

            throw $e;
        }

        // Report the failure only once every account has had its turn. The run
        // still fails, so the connection keeps its Error state, its retries and its
        // unset last_synced_at exactly as before - the one thing that changes is
        // that the accounts behind the failing one were attempted at all.
        //
        // Outside the try on purpose: by here nothing was aborted mid-run, every
        // account was attempted, and the per-account failure is already logged.
        if ($transactionFailure !== null) {
            // The balance rate limit has no channel here - the metadata only
            // travels on the success path - so a run that dies like this loses its
            // backoff and retries into an allowance it knows is spent. Throwing
            // the 429 instead would buy the backoff at the price of the sync log
            // blaming the balances endpoint for a run the transactions ended, so
            // the abort is recorded with `balance_rate_limited` and the next
            // attempt's own 429 is what applies the window.
            throw $transactionFailure;
        }

        $this->notifyRunCompleted($connection, $isFirstSync);

        return [
            'transactions_synced' => array_sum($transactionsPerBank),
            'transactions_per_bank' => $transactionsPerBank,
            'balance_failed' => $balanceFailed,
            'balance_rate_limit' => $this->rateLimitFacts($balanceRateLimitFailure),
        ];
    }

    /**
     * Record what the run had already got done when it died.
     *
     * A throwing run returns no metadata, so "this connection imported six
     * transactions and then the rate limit killed the run" was until now only
     * reconstructable by lining up timestamps across two tables.
     *
     * @param  array<string, bool|int>  $progress
     */
    private function logAbortedSync(BankingConnection $connection, \Throwable $e, array $progress): void
    {
        // A consent that lapsed mid-run is a lifecycle event the job already
        // records, not a failure worth a second warning.
        if ($e instanceof ExpiredBankingSessionException) {
            return;
        }

        Log::warning('EnableBanking sync aborted mid-run', [
            ...$connection->logContext(),
            ...$progress,
            'operation' => $e instanceof CarriesBankingOperation ? $e->operation : null,
            'status_code' => $e instanceof RequestException ? $e->response->status() : null,
            'reason' => $e::class,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * The window to ask the bank for, and the strategy a window that wide needs.
     *
     * @return array{0: string, 1: string, 2: string|null}
     */
    private function resolveWindow(BankingConnection $connection, Account $account, bool $isFirstSync): array
    {
        // A previous run ran out of page budget partway down this account's
        // history. The provider serves pages newest-first, so picking up where
        // it stopped means ending the window there instead of at today - and
        // asking for the full history again, because the watermark the routine
        // window is built on has already moved past it.
        //
        // While it drains, the account's window ends in the past, so its newest
        // transactions wait for the backfill to finish - which is why a marker
        // that cannot contribute anything is dropped rather than resumed.
        // A --full resync is an explicit request to re-pull everything, so it
        // starts over rather than resuming.
        $resumeBefore = $this->resumePoint($account, $isFirstSync);

        if ($resumeBefore !== null) {
            $dateFrom = $this->resolveDateFrom($account, $resumeBefore, forceFullWindow: true);

            // Unless the full window has since closed over the marker: it moves
            // forward a day at a time, so a marker left alone for long enough
            // ends up before the start of the history we ask for and there is
            // nothing left to walk back to. Falling through to the routine
            // window is what keeps that from inverting into date_from > date_to;
            // the run that ends without being cut short clears the marker.
            if ($dateFrom < $resumeBefore) {
                return [$dateFrom, $resumeBefore, $this->strategyFor($dateFrom)];
            }
        }

        // A first sync on a connection that has synced before can only come from
        // the --full flag, an explicit request to re-pull the whole history.
        $forceFullWindow = $isFirstSync && $connection->last_synced_at !== null;

        $dateTo = now()->toDateString();
        $dateFrom = $this->resolveDateFrom($account, $dateTo, $forceFullWindow);

        return [$dateFrom, $dateTo, $this->strategyFor($dateFrom)];
    }

    /**
     * Where a run the page budget cut short left this account's history, or
     * null when picking it up is not worth a run.
     *
     * A marker whose span the account already holds is pure cost: the run
     * spends its whole budget on history dedup will throw away, and pays for it
     * with the recent transactions the resume window's end shuts out. Measured
     * over the 2026-08-22 cycles, this was every Trade Republic account that
     * had one - one of them re-requesting 282 days it held in full, at 10
     * requests a cycle, four cycles a day, moving the marker a single day each
     * time. Falling through to the routine window imports the recent
     * transactions again; the run that follows clears the marker itself, either
     * by finishing or by being cut short over ground it already covers.
     */
    private function resumePoint(Account $account, bool $isFirstSync): ?string
    {
        if ($isFirstSync) {
            return null;
        }

        $marker = $account->transactions_paginate_before?->toDateString();

        if ($marker === null || $account->hasSyncedTransactionsBefore($marker)) {
            return null;
        }

        return $marker;
    }

    /**
     * The strategy a window starting this far back needs, or null when it is
     * narrow enough to ask for plainly.
     */
    private function strategyFor(string $dateFrom): ?string
    {
        return $dateFrom < now()->subDays(self::SHORT_WINDOW_DAYS)->toDateString() ? 'longest' : null;
    }

    /**
     * How many transaction pages one account may cost this connection's bank,
     * from `config('banking.transaction_page_budget')`. PHP_INT_MAX is
     * unbudgeted, which is every bank that has not needed an entry.
     */
    private function pageBudget(BankingConnection $connection): int
    {
        $budgets = config('banking.transaction_page_budget');

        if (! is_array($budgets)) {
            return PHP_INT_MAX;
        }

        $budget = $budgets[$connection->aspsp_name ?? ''] ?? $budgets['default'] ?? null;

        return is_int($budget) && $budget > 0 ? $budget : PHP_INT_MAX;
    }

    /**
     * Note that the bank could not serve one account's transactions, and decide
     * whether the remaining accounts are still worth trying.
     *
     * A provider that never answered will not answer for the next account either,
     * and each further attempt costs the client's full timeout against the job's
     * 120s - a connection with 26 accounts already spends a minute on the happy
     * path. Only a reply that came back with a status says something about *this*
     * account: the ConnectionException path is the one that leaves statusCode null.
     */
    private function recordAccountTransactionFailure(BankingConnection $connection, Account $account, TransientBankingProviderException $e): TransientBankingProviderException
    {
        if ($e->statusCode === null) {
            throw $e;
        }

        Log::warning('EnableBanking transaction sync failed for one account, continuing', [
            ...$connection->logContext(),
            'account_id' => $account->id,
            'status_code' => $e->statusCode,
            'provider_code' => $e->providerCode,
            'operation' => $e->operation,
            'error' => $e->getMessage(),
        ]);

        return $e;
    }

    /**
     * Fetch one account's balances, tolerating a provider that will not serve them.
     *
     * `$rateLimitFailure` is filled in with the 429 when the provider rate limited
     * the call, so the caller can hand the job what its backoff policy needs.
     *
     * Arriving with it already filled in means an earlier account in the same run
     * was rate limited: the allowance is spent for the whole consent, so this call
     * is not made at all rather than burning what is left of it.
     *
     * @return bool Whether the balances were synced
     */
    private function syncBalances(BankingConnection $connection, Account $account, ?RequestException &$rateLimitFailure): bool
    {
        if ($rateLimitFailure !== null) {
            return false;
        }

        try {
            $this->balanceSync->sync($account);

            return true;
        } catch (\Throwable $e) {
            // An expired consent needs the user to reconnect, so it still has to
            // reach the job.
            if ($e instanceof ExpiredBankingSessionException) {
                throw $e;
            }

            // A rate limit used to be rethrown so the job would apply the provider
            // backoff, which threw away a run whose transactions were already
            // persisted - and with them last_synced_at. The backoff is the part
            // worth keeping, so it is reported instead of thrown: without it the
            // remaining scheduled runs keep burning the daily allowance.
            if ($e instanceof RequestException && $e->response->status() === 429) {
                $rateLimitFailure = $e;
            }

            // No balance failure is worth losing the run over. Balances are a
            // nice-to-have next to the transactions we just persisted, and failing
            // here leaves last_synced_at unset.
            Log::warning('EnableBanking balance sync failed, continuing', [
                ...$connection->logContext(),
                'account_id' => $account->id,
                'operation' => 'balances',
                'reason' => $e::class,
                'status_code' => $e instanceof RequestException ? $e->response->status() : null,
                'rate_limited' => $rateLimitFailure !== null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * What a completed run leaves behind: the cutoff that keeps the daily email
     * from reporting a whole imported history as today's news, or the email
     * itself on every run after that.
     */
    private function notifyRunCompleted(BankingConnection $connection, bool $isFirstSync): void
    {
        // A run still walking an account's history back is importing rows dated
        // months ago, and the daily email keys on when a row was written rather
        // than when it happened. Announcing those as today's news is the very
        // thing the cutoff exists to prevent, so it moves with the backfill and
        // the email waits until there is no history left owed.
        if ($isFirstSync || $this->isBackfillingHistory($connection)) {
            $connection->update(['bank_transactions_email_cutoff_at' => now()]);

            return;
        }

        if ($connection->user->canReceiveEmails()) {
            SendDailyBankTransactionsSyncedEmailJob::dispatch($connection->user, now()->toDateString());
        }
    }

    /**
     * Whether any of this connection's accounts still owes history to a run the
     * page budget cut short.
     */
    private function isBackfillingHistory(BankingConnection $connection): bool
    {
        return $connection->accounts->contains(
            fn (Account $account) => $account->transactions_paginate_before !== null
        );
    }

    /**
     * Whether this account is still owed the daily balances derived from its
     * transactions.
     *
     * Read before the balance sync writes today's row. The backfill used to be
     * spent on the first sync alone, which was safe while a refused balance call
     * discarded the whole run and left the next one a first sync too. Now that
     * the run is kept, an account that has never had a balance is still owed it;
     * the backfill only ever writes dates it has not written.
     *
     * A linked account's balances are its counterpart's, not its own.
     */
    private function owesHistoricalBalances(Account $account, bool $isFirstSync): bool
    {
        if ($account->isLinked()) {
            return false;
        }

        return $isFirstSync || $account->balances()->doesntExist();
    }

    /**
     * Fill in the days between the balance the bank reported and the
     * transactions just imported.
     *
     * Local arithmetic, so unlike the balance request itself this half has to
     * follow the transactions - and it is tolerated the same way, because it is
     * derived data. Letting it end the run would leave `last_synced_at` unset
     * over already-persisted transactions and charge the connection a strike
     * towards being dropped from the schedule for good.
     */
    private function backfillHistoricalBalances(BankingConnection $connection, Account $account, bool $owed): void
    {
        if (! $owed) {
            return;
        }

        try {
            $this->balanceSync->calculateHistoricalBalances($account);
        } catch (\Throwable $e) {
            Log::warning('EnableBanking historical balance backfill failed, continuing', [
                ...$connection->logContext(),
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Tally what one account imported, under the bank it came from.
     *
     * @param  array<string, int>  $transactionsPerBank
     * @return array<string, int>
     */
    private function tally(array $transactionsPerBank, Account $account, int $created): array
    {
        if ($created <= 0) {
            return $transactionsPerBank;
        }

        $bankName = $account->bank->name ?? __('Unknown Bank');
        $transactionsPerBank[$bankName] = ($transactionsPerBank[$bankName] ?? 0) + $created;

        return $transactionsPerBank;
    }

    /**
     * What the provider's 429 said, in the JSON-safe shape the job's backoff
     * policy reads - the exception itself cannot go in the metadata, which is
     * persisted as JSON on the sync log. Null when no balance call was limited.
     *
     * @return array{retry_after: string|null, message: string}|null
     */
    private function rateLimitFacts(?RequestException $e): ?array
    {
        if ($e === null) {
            return null;
        }

        $body = $e->response->json();

        return [
            'retry_after' => $e->response->header('Retry-After') ?: null,
            // Capped like the response body the job logs: a provider that answers
            // an error with something enormous must not empty it into the row.
            'message' => is_array($body) ? Str::limit((string) ($body['message'] ?? ''), 500) : '',
        ];
    }

    /**
     * Start of the window to fetch for an account: just before the last
     * transaction the bank sent us, or a year back when it never sent one.
     *
     * Asking for what came after the watermark is what keeps a routine sync to
     * a handful of days. Re-paginating a year on every scheduled run is what
     * trips the provider's rate limit.
     */
    private function resolveDateFrom(Account $account, string $dateTo, bool $forceFullWindow): string
    {
        // Only bank-sourced rows move the watermark: a manual or imported
        // transaction dated later would shrink the window and skip bank history
        // for good. Trashed rows still count, because the dedup that follows
        // sees them too and would re-import nothing anyway.
        $watermark = $forceFullWindow ? null : $account->transactions()
            ->withTrashed()
            ->where('source', TransactionSource::EnableBanking)
            ->latest('transaction_date')
            ->value('transaction_date');

        if (! $watermark) {
            return now()->subYear()->toDateString();
        }

        // Future-dated rows (standing orders) must not push the window past
        // today, but the overlap applies either way.
        $start = $watermark->toDateString() > $dateTo ? now() : $watermark;

        return $start->copy()->subDays(self::WATERMARK_OVERLAP_DAYS)->toDateString();
    }
}
