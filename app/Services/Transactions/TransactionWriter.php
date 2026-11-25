<?php

namespace App\Services\Transactions;

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ManualBalanceAdjuster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creating, editing and deleting a transaction, shared by the
 * transaction MCP tools and `/api/v1/transactions`. The inputs arrive validated
 * (TransactionWriteRules) and with the account, category and labels they point
 * at already checked by the caller. Splitting and merging go through
 * TransactionSplitter, as the app's do.
 *
 * Every write goes through the model, so its events (budget assignment,
 * automation rules, AI categorization) fire as they do for the app's writes.
 * The account balance only moves when the caller asks for it.
 */
class TransactionWriter
{
    public function __construct(private ManualBalanceAdjuster $balanceAdjuster) {}

    /**
     * Automation rules run while the row is saved, so $labelIds go on top of
     * what a rule tagged it with: the goal a rule fills keeps its transaction.
     * A queue that does not wait for the commit would assign the row before
     * they arrive, so a budget tracking one of them gets a second, silent pass:
     * the row's own assignment already said what there was to say.
     *
     * @param  array<string, mixed>  $fields  `description`, `amount` and `transaction_date`, and optionally `currency_code` (the account's when left out), `category_id`, `notes`, `creditor_name` and `debtor_name`
     * @param  list<string>  $labelIds
     */
    public function create(User $user, Space $space, Account $account, array $fields, array $labelIds, bool $updateBalance): WrittenTransaction
    {
        $categoryId = self::filledOrNull($fields['category_id'] ?? null);
        $currencyCode = self::filledOrNull($fields['currency_code'] ?? null);

        [$transaction, $balanceUpdated] = DB::transaction(function () use ($user, $space, $account, $fields, $labelIds, $updateBalance, $categoryId, $currencyCode): array {
            if ($updateBalance) {
                $this->lockBalanceAccounts([$account->id]);
            }

            $transaction = new Transaction([
                'user_id' => $user->id,
                'space_id' => $space->id,
                'account_id' => $account->id,
                'category_id' => $categoryId,
                'category_source' => $categoryId === null ? null : CategorySource::Manual,
                'description' => (string) $fields['description'],
                'transaction_date' => self::day($fields['transaction_date']),
                'amount' => (int) $fields['amount'],
                'currency_code' => $currencyCode === null ? $account->currency_code : self::currency($currencyCode),
                'notes' => self::filledOrNull($fields['notes'] ?? null),
                'creditor_name' => self::filledOrNull($fields['creditor_name'] ?? null),
                'debtor_name' => self::filledOrNull($fields['debtor_name'] ?? null),
                'source' => TransactionSource::ManuallyCreated,
            ]);
            $transaction->save();

            if ($labelIds !== []) {
                $transaction->labels()->syncWithoutDetaching($labelIds);
            }

            // The adjuster no-ops on a connected account, whose balances belong
            // to the bank sync. Report what it actually did, otherwise the
            // caller says a balance moved when it did not.
            return [$transaction, $updateBalance && $this->balanceAdjuster->applyCreatedTransaction($transaction->load('account'))];
        }, attempts: 5);

        if ($labelIds !== []) {
            ReassignTransactionsToBudgets::dispatch([$transaction->id], notify: false);
        }

        return new WrittenTransaction($transaction->refresh(), $balanceUpdated);
    }

    /**
     * Change only the fields in $changes, under a row lock, where the check
     * for old split lines is made again. A changed category is always a
     * manual assignment.
     *
     * @param  array<string, mixed>  $changes  any of `description`, `amount`, `transaction_date`, `currency_code`, `account_id`, `notes`, `creditor_name`, `debtor_name` and `category_id`
     */
    public function update(Transaction $transaction, array $changes, bool $updateBalance): WrittenTransaction
    {
        return DB::transaction(function () use ($transaction, $changes, $updateBalance): WrittenTransaction {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($updateBalance) {
                $this->lockBalanceAccounts([$locked->account_id, $changes['account_id'] ?? $locked->account_id]);
            }

            $splitError = TransactionWriteRules::splitLedgerError($locked, array_keys($changes));

            if ($splitError !== null) {
                throw ValidationException::withMessages(['splits' => $splitError]);
            }

            // Snapshot the pre-edit account/date/amount so a manual balance can
            // be moved off the old values if the edit changes them.
            $original = clone $locked;

            $this->applyFields($locked, $changes);
            $locked->save();

            // Before the refresh: it drops the wasChanged() state.
            $balanceUpdated = $updateBalance && $this->moveBalance($original, $locked);

            return new WrittenTransaction($locked->refresh(), $balanceUpdated);
        }, attempts: 5);
    }

    /**
     * Soft delete, as the app does: the sync dedupes against deleted rows, so
     * it does not bring a bank transaction back. Says whether the account
     * balance moved. One part of a split is refused, as in the app.
     */
    public function delete(Transaction $transaction, bool $updateBalance): bool
    {
        return DB::transaction(function () use ($transaction, $updateBalance): bool {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $deletionError = TransactionWriteRules::deletionError($locked);

            if ($deletionError !== null) {
                throw ValidationException::withMessages(['transaction' => $deletionError]);
            }

            if ($updateBalance) {
                $this->lockBalanceAccounts([$locked->account_id]);
            }

            $balanceUpdated = $updateBalance && $this->balanceAdjuster->reverseDeletedTransaction($locked);

            $locked->delete();

            return $balanceUpdated;
        }, attempts: 5);
    }

    /**
     * One balance write at a time per account, taken before any plain read in
     * the transaction. Under REPEATABLE READ the first plain read fixes the
     * snapshot and a locking read does not, so whoever waits here still sees
     * what the writer before it committed. Taken later, a parallel write on a
     * new day would carry forward a stale balance, and the running balance
     * would end wrong with nothing to say so; on the same new day both would
     * insert its row and one would die on the unique index.
     *
     * In id order, so a move between two accounts cannot deadlock with the
     * opposite one.
     *
     * @param  list<string>  $accountIds
     */
    private function lockBalanceAccounts(array $accountIds): void
    {
        Account::query()->whereKey(array_values(array_unique($accountIds)))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /**
     * ponytail: unlike the web edit path this does not learn a correction rule:
     * writes from outside the app stay predictable and side-effect free.
     *
     * @param  array<string, mixed>  $changes
     */
    private function applyFields(Transaction $transaction, array $changes): void
    {
        $casts = [
            'description' => fn (mixed $value): string => (string) $value,
            'amount' => fn (mixed $value): int => (int) $value,
            'transaction_date' => fn (mixed $value): Carbon => Carbon::parse(self::day($value)),
            'currency_code' => self::currency(...),
            'account_id' => fn (mixed $value): string => (string) $value,
            'notes' => self::filledOrNull(...),
            'creditor_name' => self::filledOrNull(...),
            'debtor_name' => self::filledOrNull(...),
        ];

        foreach ($casts as $attribute => $cast) {
            if (array_key_exists($attribute, $changes)) {
                $transaction->{$attribute} = $cast($changes[$attribute]);
            }
        }

        // A field written in the clear retires its own iv, and only its own:
        // one left behind would have the browser decrypt plain text, and the
        // other field may still be encrypted.
        foreach (['description' => 'description_iv', 'notes' => 'notes_iv'] as $field => $iv) {
            if (array_key_exists($field, $changes)) {
                $transaction->{$iv} = null;
            }
        }

        if (array_key_exists('category_id', $changes)) {
            $this->assignCategory($transaction, self::filledOrNull($changes['category_id']));
        }
    }

    /**
     * A new category resets any AI/rule provenance, so the row is not later
     * treated as machine-categorized.
     */
    private function assignCategory(Transaction $transaction, ?string $categoryId): void
    {
        if ($categoryId === $transaction->category_id) {
            return;
        }

        $transaction->category_id = $categoryId;
        $transaction->category_source = $categoryId === null ? null : CategorySource::Manual;
        $transaction->ai_confidence = null;
        $transaction->categorized_by_rule_id = null;
    }

    /**
     * Either side no-ops on an account that does not own its balance (a
     * connected one, or a credit card), so moving a transaction onto one still
     * unwinds the account it came from.
     */
    private function moveBalance(Transaction $original, Transaction $edited): bool
    {
        if (! TransactionWriteRules::movesBalance($edited) || ! $edited->wasChanged(ManualBalanceAdjuster::BALANCE_AFFECTING_ATTRIBUTES)) {
            return false;
        }

        $reversed = $this->balanceAdjuster->reverseDeletedTransaction($original);
        $applied = $this->balanceAdjuster->applyCreatedTransaction($edited->load('account'));

        return $reversed || $applied;
    }

    /**
     * In capitals. The rule only lets ASCII letters through.
     */
    private static function currency(mixed $value): string
    {
        return strtoupper((string) $value);
    }

    /**
     * The day of a date sent with or without a time, as `balance_date` is kept.
     */
    private static function day(mixed $value): string
    {
        return Carbon::parse((string) $value)->toDateString();
    }

    /**
     * A string field, or null when an empty value was sent to clear it.
     */
    private static function filledOrNull(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}
