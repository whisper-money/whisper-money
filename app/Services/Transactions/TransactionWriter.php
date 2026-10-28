<?php

namespace App\Services\Transactions;

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ManualBalanceAdjuster;
use Illuminate\Support\Carbon;

/**
 * Creating, editing, categorizing and deleting a transaction, shared by the
 * transaction MCP tools and `/api/v1/transactions`. The inputs arrive
 * validated (TransactionWriteRules) and with the account, category and labels
 * they point at already checked to be in the space.
 *
 * Every write goes through the model, so its events (budget assignment,
 * automation rules, AI categorization) fire as they do for any other write.
 * The account balance only moves when the caller asks for it.
 */
class TransactionWriter
{
    public function __construct(private ManualBalanceAdjuster $balanceAdjuster) {}

    /**
     * @param  array<string, mixed>  $fields  `description`, `amount` and `transaction_date`, and optionally `currency_code` (the account's when left out), `category_id`, `notes`, `creditor_name` and `debtor_name`
     * @param  list<string>  $labelIds
     */
    public function create(User $user, Space $space, Account $account, array $fields, array $labelIds, bool $updateBalance): WrittenTransaction
    {
        $categoryId = self::filledOrNull($fields['category_id'] ?? null);
        $currencyCode = self::filledOrNull($fields['currency_code'] ?? null);

        $transaction = new Transaction([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'account_id' => $account->id,
            'category_id' => $categoryId,
            'category_source' => $categoryId === null ? null : CategorySource::Manual,
            'description' => (string) $fields['description'],
            'transaction_date' => (string) $fields['transaction_date'],
            'amount' => (int) $fields['amount'],
            'currency_code' => $currencyCode === null ? $account->currency_code : mb_strtoupper($currencyCode),
            'notes' => self::filledOrNull($fields['notes'] ?? null),
            'creditor_name' => self::filledOrNull($fields['creditor_name'] ?? null),
            'debtor_name' => self::filledOrNull($fields['debtor_name'] ?? null),
            'source' => TransactionSource::ManuallyCreated->value,
        ]);
        $transaction->save();

        if ($labelIds !== []) {
            $transaction->labels()->sync($labelIds);
        }

        // The adjuster no-ops on a connected account, whose balances belong to
        // the bank sync. Report what it actually did, otherwise the caller says
        // a balance moved when it did not.
        $balanceUpdated = $updateBalance
            && $this->balanceAdjuster->applyCreatedTransaction($transaction->load('account'));

        return new WrittenTransaction($transaction, $balanceUpdated);
    }

    /**
     * Change only the fields in $changes. A changed category is always a
     * manual assignment.
     *
     * @param  array<string, mixed>  $changes  any of the TransactionWriteRules::CoreFields, `notes` and `category_id`
     */
    public function update(Transaction $transaction, array $changes, bool $updateBalance): WrittenTransaction
    {
        // Snapshot the pre-edit account/date/amount so a manual balance can be
        // moved off the old values if the edit changes them.
        $originalSnapshot = clone $transaction;

        $casts = [
            'description' => fn (mixed $value): string => (string) $value,
            'amount' => fn (mixed $value): int => (int) $value,
            'transaction_date' => fn (mixed $value): Carbon => Carbon::parse((string) $value),
            'currency_code' => fn (mixed $value): string => mb_strtoupper((string) $value),
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

        // ponytail: unlike the web edit path this does not learn a correction
        // rule — writes from outside the app stay predictable and side-effect
        // free.
        if (array_key_exists('category_id', $changes)) {
            $categoryId = self::filledOrNull($changes['category_id']);

            if ($categoryId !== $transaction->category_id) {
                $this->assignCategory($transaction, $categoryId);
            }
        }

        $transaction->save();

        $balanceUpdated = false;

        if ($updateBalance && $transaction->wasChanged(ManualBalanceAdjuster::BALANCE_AFFECTING_ATTRIBUTES)) {
            // Either side no-ops on an account that does not own its balance (a
            // connected one, or a credit card under the credit card feature), so
            // moving a transaction onto one still unwinds the account it came from.
            $reversed = $this->balanceAdjuster->reverseDeletedTransaction($originalSnapshot);
            $applied = $this->balanceAdjuster->applyCreatedTransaction($transaction->load('account'));
            $balanceUpdated = $reversed || $applied;
        }

        return new WrittenTransaction($transaction->refresh(), $balanceUpdated);
    }

    /**
     * Set the category, or clear it with null, as a manual assignment even
     * when it does not change.
     */
    public function categorize(Transaction $transaction, ?string $categoryId): Transaction
    {
        $this->assignCategory($transaction, $categoryId);
        $transaction->save();

        return $transaction->refresh();
    }

    /**
     * Delete a transaction TransactionWriteRules::deletionError() lets go,
     * and say whether the account balance moved.
     */
    public function delete(Transaction $transaction, bool $updateBalance): bool
    {
        $balanceUpdated = $updateBalance
            && $this->balanceAdjuster->reverseDeletedTransaction($transaction);

        $transaction->delete();

        return $balanceUpdated;
    }

    /**
     * A category set from outside the app resets any AI/rule provenance, so
     * the row is not later treated as machine-categorized.
     */
    private function assignCategory(Transaction $transaction, ?string $categoryId): void
    {
        $transaction->category_id = $categoryId;
        $transaction->category_source = $categoryId === null ? null : CategorySource::Manual;
        $transaction->ai_confidence = null;
        $transaction->categorized_by_rule_id = null;
    }

    /**
     * A string field, or null when an empty value was sent to clear it.
     */
    private static function filledOrNull(mixed $value): ?string
    {
        return filled($value) ? (string) $value : null;
    }
}
