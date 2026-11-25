<?php

namespace App\Services\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Rules\StorableDate;

/**
 * The transaction write rules, shared by the create and update transaction
 * MCP tools and the `/api/v1/transactions` form requests, and what `/api/v1`
 * refuses on top of them. Looking up the account, category and labels a write
 * points at is left to each caller, which names the valid ids its own way.
 */
class TransactionWriteRules
{
    /**
     * The fields that describe the transaction itself. As in the app, a split
     * part takes all of them from its original, so they are locked there. A
     * bank/imported row owns them through its sync, except its date, which
     * stays editable like in the app's edit dialog: which month a transaction
     * counts towards is the user's call, and the model keeps the source's date
     * in `source_date`. Notes and category_id stay editable on any transaction.
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
     * Well under what the TEXT columns hold, so oversized input is a validation
     * error instead of a failed insert.
     */
    private const int DescriptionMaxLength = 1000;

    private const int NotesMaxLength = 10000;

    /**
     * In minor units on either side of zero: ten trillion of a two-decimal
     * currency, past any real transaction, and far enough from the 64-bit
     * limit that negating an amount, adding it to a balance or weighing it by
     * an ownership percentage cannot overflow.
     */
    private const int MaxAmount = 1_000_000_000_000_000;

    /**
     * @return array<string, array<mixed>>
     */
    public static function createRules(): array
    {
        return [
            'account_id' => ['required', 'string'],
            'description' => ['required', 'string', 'max:'.self::DescriptionMaxLength],
            'amount' => ['required', ...self::amountRules()],
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
            'amount' => ['sometimes', ...self::amountRules()],
            'transaction_date' => ['sometimes', 'date', new StorableDate],
            ...self::optionalFieldRules(),
        ];
    }

    /**
     * Why `/api/v1` refuses to edit the $sent fields, keyed by the field the
     * error goes under: the locked core fields ({@see self::lockedCoreFields()}),
     * and blanking a note the server cannot read. The ledger of a row still
     * split into the old lines is TransactionWriter's to refuse, under the row
     * lock.
     *
     * @param  array<string, mixed>  $sent  the fields the edit carries
     * @return array<string, string>
     */
    public static function editRefusals(Transaction $transaction, array $sent): array
    {
        return [
            ...self::lockedCoreFieldRefusals($transaction, array_keys($sent)),
            ...array_filter(['notes' => self::blankedEncryptedNotesError($transaction, $sent)]),
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
    private static function lockedCoreFields(Transaction $transaction, array $sent): array
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
     * Why an edit of the $sent fields cannot touch a transaction still split
     * into the old lines, or null when it can. Such a row is filed under its
     * lines, so its category and amount belong to them until it is converted
     * to parts. The wording is the app's.
     *
     * @param  list<string>  $sent  the fields the edit carries
     */
    public static function splitLedgerError(Transaction $transaction, array $sent): ?string
    {
        if (array_intersect(['category_id', 'amount'], $sent) === [] || ! $transaction->splits()->exists()) {
            return null;
        }

        return 'This transaction is still split into lines the old way, so its category and amount belong to those lines until it is converted to the new split model.';
    }

    /**
     * Whether the edit may move the account's balance snapshots: a row that
     * came from a bank or import never does, even moved to another date, as
     * in the app's edit dialog.
     */
    public static function movesBalance(Transaction $transaction): bool
    {
        return $transaction->source === TransactionSource::ManuallyCreated;
    }

    /**
     * {@see self::lockedCoreFieldError()} under each locked field it names.
     *
     * @param  list<string>  $sent
     * @return array<string, string>
     */
    private static function lockedCoreFieldRefusals(Transaction $transaction, array $sent): array
    {
        $error = self::lockedCoreFieldError($transaction, $sent);

        return $error === null ? [] : array_fill_keys(self::lockedCoreFields($transaction, $sent), $error);
    }

    /**
     * A note encrypted on the device reads as '', so a client writing back
     * what it read would send it blank. New text replaces the note on purpose;
     * a blank value would only wipe something nobody can read here.
     *
     * @param  array<string, mixed>  $sent
     */
    private static function blankedEncryptedNotesError(Transaction $transaction, array $sent): ?string
    {
        if ($transaction->notes_iv === null || ! array_key_exists('notes', $sent) || filled($sent['notes'])) {
            return null;
        }

        return 'These notes are encrypted on your device and read as empty here, so a blank value would erase them. Send new text to replace them, or leave notes out.';
    }

    /**
     * @return list<string>
     */
    private static function amountRules(): array
    {
        // Bail: `between` cannot read what `integer` refuses, and 1e999 decodes
        // to INF.
        return ['bail', 'integer', 'between:-'.self::MaxAmount.','.self::MaxAmount];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function optionalFieldRules(): array
    {
        return [
            // Any code, not just the ones an account can be in: a trip paid in a
            // currency the app holds no account for is a real transaction. With
            // no rate for it, totals count the amount unconverted and a balance
            // stays where it is, with `balance_updated` saying so. Three ASCII
            // letters, as Intl.NumberFormat takes no other shape in the browser;
            // \z, since $ would let a trailing newline through.
            'currency_code' => ['sometimes', 'string', 'regex:/\A[A-Za-z]{3}\z/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.self::NotesMaxLength],
            'creditor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'debtor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
