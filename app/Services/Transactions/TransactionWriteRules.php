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
     * The fields that describe the transaction itself. As in the app, a split
     * part takes all of them from its original, so they are locked there. A
     * bank/imported row owns them through its sync, except its date, which
     * stays editable like in the app (a payroll booked on the 27th can belong
     * to next month). Notes and category_id stay editable on any transaction.
     *
     * @var list<string>
     */
    private const array CoreFields = [
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
     * The core fields among $sent that the transaction does not let change:
     * all of them on a split part, all but the date on a bank/imported row,
     * none on a manually-created one.
     *
     * @param  list<string>  $sent  the fields the edit carries
     * @return list<string>
     */
    public static function lockedCoreFields(Transaction $transaction, array $sent): array
    {
        $sentCoreFields = array_values(array_intersect(self::CoreFields, $sent));

        if ($transaction->isSplitPart()) {
            return $sentCoreFields;
        }

        if ($transaction->source !== TransactionSource::ManuallyCreated) {
            return array_values(array_diff($sentCoreFields, ['transaction_date']));
        }

        return [];
    }

    /**
     * Why an edit of the $sent fields cannot go through, or null when it can.
     * Only an edit that actually carries a locked core field is refused: one
     * limited to notes or the category is allowed on every transaction, which
     * is how bank/imported rows and split parts get annotated.
     *
     * @param  list<string>  $sent  the fields the edit carries
     * @param  string  $labelsHint  appended to the message, naming where the labels are changed
     */
    public static function lockedCoreFieldError(Transaction $transaction, array $sent, string $labelsHint = ''): ?string
    {
        $locked = self::lockedCoreFields($transaction, $sent);

        if ($locked === []) {
            return null;
        }

        $fields = implode(', ', $locked);

        if ($transaction->isSplitPart()) {
            return "This transaction is one part of a split, so it cannot change {$fields} — those come from the original. Notes and category_id can still be edited here{$labelsHint}.";
        }

        return "Only manually-created transactions can change {$fields}. This one came from a bank or import, so those fields are locked; transaction_date, notes and category_id can still be edited here{$labelsHint}.";
    }

    /**
     * Why the transaction cannot be deleted, or null when it can. As in the
     * app, any transaction can, bank/imported ones included (a soft delete the
     * next sync does not bring back), except one part of a split.
     */
    public static function deletionError(Transaction $transaction): ?string
    {
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
