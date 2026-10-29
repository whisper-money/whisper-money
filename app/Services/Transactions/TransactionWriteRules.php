<?php

namespace App\Services\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Rules\StorableDate;
use App\Services\CurrencyOptions;
use Illuminate\Validation\Rule;

/**
 * The transaction write rules and refusals, shared by the create, update and
 * delete transaction MCP tools and the `/api/v1/transactions` form requests,
 * so the two write paths cannot drift apart. Looking up the account, category
 * and labels a write points at is left to each caller, which names the valid
 * ids its own way.
 */
class TransactionWriteRules
{
    /**
     * The fields that describe the transaction itself. A bank/imported row owns
     * them through its sync, and a split part takes them from its original, so
     * they are locked there — while notes and category_id stay editable on any
     * transaction.
     *
     * @var list<string>
     */
    public const array CoreFields = [
        'description',
        'amount',
        'transaction_date',
        'currency_code',
        'account_id',
        'creditor_name',
        'debtor_name',
    ];

    /**
     * Well under what the TEXT columns hold, so oversized input is a 422
     * instead of a failed insert.
     */
    private const int DescriptionMaxLength = 1000;

    private const int NotesMaxLength = 10000;

    /**
     * @return array<string, array<mixed>>
     */
    public static function createRules(): array
    {
        return [
            'account_id' => ['required', 'string'],
            'description' => ['required', 'string', 'max:'.self::DescriptionMaxLength],
            'amount' => ['required', 'integer'],
            'transaction_date' => ['required', 'date', new StorableDate],
            ...self::optionalFieldRules(),
        ];
    }

    /**
     * Every field is optional: only what is sent changes.
     *
     * @return array<string, array<mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'description' => ['sometimes', 'string', 'max:'.self::DescriptionMaxLength],
            'amount' => ['sometimes', 'integer'],
            'transaction_date' => ['sometimes', 'date', new StorableDate],
            ...self::optionalFieldRules(),
        ];
    }

    /**
     * Why an edit of the $sent fields cannot go through, or null when it can.
     * Only an edit that actually carries a core field is refused: one limited
     * to notes or the category is allowed on every transaction, which is how
     * bank/imported rows and split parts get annotated.
     *
     * @param  list<string>  $sent  the fields the edit carries
     * @param  string  $labelsHint  appended to the message, naming where the labels are changed
     */
    public static function lockedCoreFieldError(Transaction $transaction, array $sent, string $labelsHint = ''): ?string
    {
        $locked = array_values(array_intersect(self::CoreFields, $sent));

        if ($locked === []) {
            return null;
        }

        $fields = implode(', ', $locked);

        if ($transaction->source !== TransactionSource::ManuallyCreated) {
            return "Only manually-created transactions can change {$fields}. This one came from a bank or import, so its core fields are locked; notes and category_id can still be edited here{$labelsHint}.";
        }

        if ($transaction->isSplitPart()) {
            return "This transaction is one part of a split, so it cannot change {$fields} — those come from the original. Notes and category_id can still be edited here{$labelsHint}.";
        }

        return null;
    }

    /**
     * Why the transaction cannot be deleted, or null when it can: only a
     * manually-created one that is not one part of a split can.
     */
    public static function deletionError(Transaction $transaction): ?string
    {
        if ($transaction->source !== TransactionSource::ManuallyCreated) {
            return 'Only manually-created transactions can be deleted. This one came from a bank or import.';
        }

        if ($transaction->isSplitPart()) {
            return 'This transaction is one part of a split, so deleting it on its own would leave the rest no longer adding up. Merge the split back first.';
        }

        return null;
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function optionalFieldRules(): array
    {
        return [
            'currency_code' => ['sometimes', 'string', Rule::in(app(CurrencyOptions::class)->accountCodes())],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.self::NotesMaxLength],
            'creditor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'debtor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
