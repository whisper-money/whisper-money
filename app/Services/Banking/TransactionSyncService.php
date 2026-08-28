<?php

namespace App\Services\Banking;

use App\Contracts\BankingProviderInterface;
use App\Enums\TransactionSource;
use App\Exceptions\Banking\WrongTransactionsPeriodException;
use App\Models\Account;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class TransactionSyncService
{
    /**
     * Fallback lookback windows (in days back from date_to) tried in order when
     * the bank rejects the requested transactions period as too wide. Ordered
     * widest-first so the user keeps as much history as the bank will serve;
     * the last step is the floor before the account is skipped.
     *
     * @var list<int>
     */
    private const array WRONG_PERIOD_LOOKBACK_DAYS = [90, 30, 7];

    public function __construct(
        private BankingProviderInterface $provider,
        private TransactionDescriptionFormatter $descriptionFormatter,
    ) {}

    /**
     * Sync transactions for a connected account.
     *
     * `$pageBudget` caps how many pages this run will fetch, for banks that
     * meter a consent so tightly that unbounded pagination spends the whole
     * allowance before the sync reaches anything else. The default is as many
     * as it takes, which is what every bank without an entry in
     * `config('banking.transaction_page_budget')` gets.
     *
     * @return int Number of new transactions created
     */
    public function sync(Account $account, string $dateFrom, string $dateTo, ?string $strategy = null, bool $saveDailyBalances = true, int $pageBudget = PHP_INT_MAX): int
    {
        if (! $account->external_account_id) {
            return 0;
        }

        $created = 0;
        $pages = 0;
        $oldestSeen = null;
        $continuationKey = null;
        $dailyBalances = [];
        $bankName = $account->bank?->name;

        // Preload the account's existing dedup keys once. Without this every
        // incoming transaction ran its own exists() probe (the N+1 in
        // PHP-LARAVEL-3Y). Keys inserted during this run are folded back into
        // the sets so duplicates within the same sync are still caught in
        // memory, and the unique index still backstops concurrent syncs.
        // ponytail: loads every key for the account; if one account's history
        // ever dwarfs its sync window, narrow this to the incoming batch's keys.
        [$knownFingerprints, $knownExternalIds] = $this->loadExistingDedupKeys($account);

        // The bank can reject the requested window as too wide (HTTP 422). When
        // that happens, restart the account from the first page with a
        // progressively narrower window so the user still gets the history the
        // bank is willing to serve, instead of crashing the whole connection
        // sync. Re-fetched pages are idempotent (dedup skips already-imported
        // rows; daily balances are keyed by date), and strategy is dropped on
        // the narrowed retry so the explicit date_from is honoured rather than
        // overridden by "longest".
        // `$pages` deliberately survives a narrowed retry: the budget counts the
        // requests this run has made, not the ones this attempt has made, so a
        // bank that refuses two windows before serving one cannot spend three
        // budgets on a single account.
        while (true) {
            try {
                $continuationKey = null;

                do {
                    $result = $this->provider->getTransactions(
                        $account->external_account_id,
                        $dateFrom,
                        $dateTo,
                        $continuationKey,
                        $strategy,
                    );

                    $pages++;

                    foreach ($result['transactions'] as $transaction) {
                        if ($this->importTransaction($account, $transaction, $bankName, $knownFingerprints, $knownExternalIds)) {
                            $created++;
                        }

                        if ($saveDailyBalances) {
                            $this->trackDailyBalance($transaction, $dailyBalances, $account->currency_code);
                        }
                    }

                    $oldestSeen = $this->oldestDate($oldestSeen, $result['transactions']);
                    $continuationKey = $result['continuation_key'];
                } while ($continuationKey && $pages < $pageBudget);

                break;
            } catch (WrongTransactionsPeriodException $e) {
                $narrowedDateFrom = $this->nextNarrowerDateFrom($dateFrom, $dateTo);

                if ($narrowedDateFrom === null) {
                    throw $e;
                }

                Log::warning('EnableBanking rejected the transactions period; retrying with a narrower window', [
                    'account_id' => $account->id,
                    'rejected_date_from' => $dateFrom,
                    'retry_date_from' => $narrowedDateFrom,
                    'date_to' => $dateTo,
                ]);

                $dateFrom = $narrowedDateFrom;
                $strategy = null;
            }
        }

        if ($saveDailyBalances) {
            $this->saveDailyBalances($account, $dailyBalances);
        }

        $this->recordPaginationFrontier($account, $continuationKey !== null, $oldestSeen, $dateTo, $created);

        Log::info('Synced transactions', [
            'account_id' => $account->id,
            'new_transactions' => $created,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'pages' => $pages,
        ]);

        return $created;
    }

    /**
     * The earliest booking date across a page and everything seen before it.
     *
     * Dates are 'Y-m-d', where string order is date order.
     *
     * @param  array<int, array<string, mixed>>  $transactions
     */
    private function oldestDate(?string $oldestSeen, array $transactions): ?string
    {
        foreach ($transactions as $transaction) {
            $date = $this->parseDate($transaction);
            $oldestSeen = $oldestSeen === null || $date < $oldestSeen ? $date : $oldestSeen;
        }

        return $oldestSeen;
    }

    /**
     * Remember where a run the page budget cut short stopped, so the next one
     * resumes there instead of re-reading the pages this one already has.
     *
     * The marker has to exist because the window start is derived from the
     * *newest* transaction we hold, while the provider paginates
     * **newest-first** - verified 2026-08-21 against production insert order,
     * which runs newest to oldest on every bank we sync, Trade Republic
     * included. A truncated run therefore holds the recent end of the window
     * and moves that watermark to today, so without a marker the next run would
     * ask for the same recent days again and the older history would never be
     * reached.
     *
     * Cleared by a run that paginated to the end, by one that was cut short
     * without reaching anything older than it was already asked for, and by one
     * whose next frontier would be spent on history the account already holds:
     * there is nothing further back to walk to, and keeping the marker would
     * re-request the same window every cycle while shutting out everything
     * recent.
     */
    private function recordPaginationFrontier(Account $account, bool $truncated, ?string $oldestSeen, string $dateTo, int $created): void
    {
        $current = $account->transactions_paginate_before?->toDateString();
        $frontier = $this->nextFrontier($truncated, $oldestSeen, $dateTo);

        if ($frontier !== null && $this->backfillIsSpent($account, $frontier, resumed: $current !== null, created: $created)) {
            $frontier = null;
        }

        if ($truncated) {
            Log::warning('Transaction page budget stopped the sync early', [
                'account_id' => $account->id,
                'date_to' => $dateTo,
                'oldest_reached' => $oldestSeen,
                'resume_before' => $frontier,
                // The marker this run arrived with and is not handing on, so a
                // backfill that gave up is greppable rather than inferred from
                // a resume_before that silently turned null.
                'dropped_marker' => $frontier === null ? $current : null,
                // The run spent its whole budget without getting past the date
                // it was already asked for, so the day it stopped on is stepped
                // over rather than re-read. Worth seeing in the logs.
                'skipped_remainder_of' => $oldestSeen !== null && $oldestSeen >= $dateTo ? $dateTo : null,
            ]);
        }

        if ($frontier === $current) {
            return;
        }

        $account->update(['transactions_paginate_before' => $frontier]);
    }

    /**
     * Whether walking further back has stopped being worth a run, so the marker
     * is dropped and the account goes back to the routine window that ends
     * today. Two ways to know, both measured on Trade Republic on 2026-08-22:
     *
     *  - The span behind the frontier is already synced. The provider was being
     *    asked for months it had already served, at 10 requests a cycle, for
     *    dedup to throw all of it away.
     *  - A run that resumed a marker imported nothing at all. 111 transaction
     *    requests bought 2 transactions across one cycle, because the pages come
     *    back nearly empty; a budget spent on that while the window's end keeps
     *    every recent transaction out is a run better not repeated.
     *
     * The cost is a history the bank does hold but pages too thinly to reach:
     * that account keeps whatever it has and stops walking. Recent transactions
     * are worth more than a backfill that moves a day per cycle, and a --full
     * resync still asks for everything.
     */
    private function backfillIsSpent(Account $account, string $frontier, bool $resumed, int $created): bool
    {
        return ($resumed && $created === 0) || $account->hasSyncedTransactionsBefore($frontier);
    }

    /**
     * Where the next run picks this account's history up, or null when there is
     * nothing left to pick up.
     *
     * Every branch returns either null or a date strictly earlier than the one
     * this run was asked for, which is what makes the walk terminate: a run
     * either finishes the history, finds none, or moves the frontier back by at
     * least a day.
     */
    private function nextFrontier(bool $truncated, ?string $oldestSeen, string $dateTo): ?string
    {
        // Paginated to the end, or the window held nothing at all: nothing owed.
        if (! $truncated || $oldestSeen === null) {
            return null;
        }

        // The run walked back past the end of the window it was given, which is
        // the ordinary case: resume from the oldest date it reached.
        if ($oldestSeen < $dateTo) {
            return $oldestSeen;
        }

        // The budget ran out without the run getting past that date at all -
        // pages carrying nothing older, or a single day with more pages than the
        // budget. Resuming at the same date would re-read the same pages every
        // cycle and never reach the history behind them, so step over the day.
        //
        // ponytail: costs the tail of that one day. Persisting the provider's
        // own continuation key would resume exactly instead, if a bank ever
        // turns out to page a single day more finely than its budget allows.
        return Carbon::parse($dateTo)->subDay()->toDateString();
    }

    /**
     * The next window start strictly narrower (later) than the current one,
     * stepping down the bounded lookback ladder. Returns null when no ladder
     * step narrows the current window, so the caller gives up on the account
     * rather than looping forever. Candidates are always <= date_to, so the
     * window never inverts. Dates are 'Y-m-d', where string order is date order.
     */
    private function nextNarrowerDateFrom(string $dateFrom, string $dateTo): ?string
    {
        $to = Carbon::parse($dateTo);

        foreach (self::WRONG_PERIOD_LOOKBACK_DAYS as $days) {
            $candidate = $to->copy()->subDays($days)->toDateString();

            if ($candidate > $dateFrom) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Import a single transaction, skipping duplicates.
     *
     * Dedup strategy: every transaction is keyed by a deterministic
     * fingerprint stored in `dedup_fingerprint` and protected by a
     * `(account_id, dedup_fingerprint)` unique index. The upstream
     * `transaction_id` / `entry_reference` is still preserved in
     * `external_transaction_id` when present, for traceability.
     *
     * This protects against:
     *  - Banks (e.g. BNP Paribas Fortis) that omit any stable id for
     *    certain card transactions, which previously bypassed dedup.
     *  - Race conditions between overlapping sync runs.
     */
    private function importTransaction(Account $account, array $data, ?string $bankName, array &$knownFingerprints, array &$knownExternalIds): bool
    {
        if (TransactionSettlement::isUnsettled($data)) {
            return false;
        }

        $externalId = $data['transaction_id'] ?? $data['entry_reference'] ?? null;
        $fingerprint = TransactionFingerprint::for($data, $bankName);

        // Mirror of the previous exists() probe against the preloaded sets:
        // match on the fingerprint, or — for legacy rows keyed solely on the
        // upstream id before the fingerprint column existed — the external id.
        $exists = isset($knownFingerprints[$fingerprint])
            || ($externalId !== null && isset($knownExternalIds[$this->dedupExternalIdKey($externalId)]));

        if ($exists) {
            return false;
        }

        $currency = $data['transaction_amount']['currency'] ?? $account->currency_code;
        $amount = $this->parseAmount($data, $currency);
        $rawDescription = $this->parseDescription($data);
        $formatted = $this->descriptionFormatter->format($rawDescription, $bankName);
        $counterparties = TransactionCounterpartyExtractor::fromPayload($data);
        $transactionDate = $this->parseDate($data);

        try {
            $account->transactions()->create([
                'user_id' => $account->user_id,
                'space_id' => $account->space_id,
                'description' => $formatted['description'],
                'description_iv' => null,
                'original_description' => $formatted['original_description'],
                'transaction_date' => $transactionDate,
                'amount' => $amount,
                'currency_code' => $currency,
                'notes' => null,
                'notes_iv' => null,
                'source' => TransactionSource::EnableBanking,
                'external_transaction_id' => $externalId,
                'dedup_fingerprint' => $fingerprint,
                'raw_data' => $data,
                ...$counterparties,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent sync inserted the same fingerprint between our
            // exists() check and the insert. Treat as duplicate.
            return false;
        }

        $knownFingerprints[$fingerprint] = true;

        if ($externalId !== null) {
            $knownExternalIds[$this->dedupExternalIdKey($externalId)] = true;
        }

        return true;
    }

    /**
     * Normalize an external transaction id for dedup lookups so matching stays
     * case-insensitive, mirroring the production `utf8mb4_unicode_ci` collation
     * the old `where external_transaction_id = ?` probe relied on. Without this
     * a legacy id stored as `ABC` would no longer dedup an incoming `abc`, and
     * since there is no unique index on `external_transaction_id` that would
     * silently double-import the transaction. (Accent/width folding is not
     * replicated; bank reference ids are ASCII in practice.)
     */
    private function dedupExternalIdKey(string $externalId): string
    {
        return mb_strtolower($externalId);
    }

    /**
     * Preload the account's existing dedup keys, including soft-deleted rows,
     * so duplicate detection runs against in-memory sets instead of one
     * exists() query per incoming transaction.
     *
     * @return array{0: array<string, true>, 1: array<string, true>} fingerprints keyed set, external ids keyed set
     */
    private function loadExistingDedupKeys(Account $account): array
    {
        $knownFingerprints = [];
        $knownExternalIds = [];

        // cursor() streams rows so peak memory is the two sets, not an extra
        // buffered Collection of every historical row on top of them.
        $rows = $account->transactions()
            ->withTrashed()
            ->toBase()
            ->select(['dedup_fingerprint', 'external_transaction_id'])
            ->cursor();

        foreach ($rows as $row) {
            if ($row->dedup_fingerprint !== null) {
                $knownFingerprints[$row->dedup_fingerprint] = true;
            }

            if ($row->external_transaction_id !== null) {
                $knownExternalIds[$this->dedupExternalIdKey($row->external_transaction_id)] = true;
            }
        }

        return [$knownFingerprints, $knownExternalIds];
    }

    /**
     * Parse amount from EnableBanking transaction data.
     * Returns the amount in the given currency's minor units, which are not
     * always cents. Debits are negative.
     */
    private function parseAmount(array $data, string $currency): int
    {
        $rawAmount = $data['transaction_amount']['amount'] ?? '0';
        $cents = Money::toMinor(floatval($rawAmount), $currency);

        $indicator = $data['credit_debit_indicator'] ?? null;

        if ($indicator === 'DBIT') {
            return -abs($cents);
        }

        return abs($cents);
    }

    /**
     * Parse description from EnableBanking transaction data.
     */
    private function parseDescription(array $data): string
    {
        $remittanceInfo = $data['remittance_information'] ?? [];

        if (! empty($remittanceInfo)) {
            return implode(' ', $remittanceInfo);
        }

        return $data['creditor']['name']
            ?? $data['debtor']['name']
            ?? 'Bank transaction';
    }

    /**
     * Parse transaction date, preferring booking_date.
     */
    private function parseDate(array $data): string
    {
        return $data['booking_date']
            ?? $data['transaction_date']
            ?? $data['value_date']
            ?? now()->toDateString();
    }

    /**
     * Track the balance after transaction for each day.
     * Overwrites so only the last transaction's balance per day is kept.
     *
     * @param  array<string, int>  $dailyBalances
     */
    private function trackDailyBalance(array $transaction, array &$dailyBalances, string $currency): void
    {
        $balanceAfter = $transaction['balance_after_transaction'] ?? null;

        if (! $balanceAfter || ! isset($balanceAfter['amount'])) {
            return;
        }

        $date = $this->parseDate($transaction);
        // The snapshot lands in `account_balances`, which holds the
        // account's own currency.
        $amount = Money::toMinor(floatval($balanceAfter['amount']), $currency);

        $dailyBalances[$date] = $amount;
    }

    /**
     * Save tracked daily balances to the account.
     *
     * @param  array<string, int>  $dailyBalances
     */
    private function saveDailyBalances(Account $account, array $dailyBalances): void
    {
        foreach ($dailyBalances as $date => $balance) {
            $account->balances()->updateOrCreate(
                ['balance_date' => $date],
                ['balance' => $balance],
            );
        }
    }
}
