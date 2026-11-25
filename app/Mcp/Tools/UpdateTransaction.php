<?php

namespace App\Mcp\Tools;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit a transaction; only the fields you pass change. Notes and category work on any transaction, bank/imported ones and split parts included; the other fields only on manual ones.')]
class UpdateTransaction extends WriteTool
{
    /**
     * The fields that describe the transaction itself. A bank/imported row owns
     * them through its sync, so they are locked there — while notes and
     * category_id stay editable on any transaction.
     *
     * @var list<string>
     */
    private const CORE_FIELDS = [
        'description',
        'amount',
        'transaction_date',
        'currency_code',
        'account_id',
        'creditor_name',
        'debtor_name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the transaction to edit.')->required(),
            'description' => $schema->string()->description('New description.'),
            'amount' => $schema->integer()->description('New signed amount, in the minor units of the transaction\'s own currency.'),
            'transaction_date' => $schema->string()->description('New transaction date, YYYY-MM-DD.'),
            'currency_code' => $schema->string()->description('New ISO 4217 currency code (3 letters).'),
            'account_id' => $schema->string()->description('Move the transaction to another account.'),
            'category_id' => $schema->string()->nullable()->description('New category id, or null to clear the category.'),
            'creditor_name' => $schema->string()->nullable()->description('New creditor (payee) name, or null to clear it.'),
            'debtor_name' => $schema->string()->nullable()->description('New debtor (payer) name, or null to clear it.'),
            'notes' => $schema->string()->nullable()->description('New free-text notes, or null to clear them. Editable on any transaction, bank/imported ones included.'),
            'update_balance' => $schema->boolean()->description('When true and the amount/date/account changed, move the account balance snapshots accordingly. Ignored on connected accounts, whose balances come from the bank, and on credit cards that track a limit instead of a balance. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        $refusal = $this->refuseUneditable($request, $transaction);

        if ($refusal !== null) {
            return $refusal;
        }

        $request->validate(TransactionWriteRules::updateRules());

        // The writer locks and opens its own transaction: wrapping these reads
        // in one would fix the snapshot before its balance lock. It also checks
        // for a split again under the lock.
        //
        // ponytail: unlike the web edit path this does not learn a correction
        // rule: MCP writes stay predictable and side-effect free.
        $written = app(TransactionWriter::class)->update($transaction, $this->requestedFields($request, [
            'description' => fn () => $request->string('description')->toString(),
            'amount' => fn () => $request->integer('amount'),
            'transaction_date' => fn () => $request->string('transaction_date')->toString(),
            'currency_code' => fn () => $request->string('currency_code')->toString(),
            'account_id' => fn () => $this->accountInSpace($request, $space)->id,
            'notes' => fn () => $this->nullableString($request, 'notes'),
            'creditor_name' => fn () => $this->nullableString($request, 'creditor_name'),
            'debtor_name' => fn () => $this->nullableString($request, 'debtor_name'),
            'category_id' => fn () => $request->filled('category_id') ? $this->categoryInSpace($request, $space)->id : null,
        ]), $request->boolean('update_balance'));

        return $this->json([
            'transaction' => $this->presentTransaction($written->transaction),
            'balance_updated' => $written->balanceUpdated,
        ]);
    }

    /**
     * Why the request cannot go through, as the error the client sees, or null
     * when it can. Only a request that actually carries a locked field is
     * refused: an edit limited to notes is allowed on every transaction, which
     * is how an agent annotates bank and imported rows and split parts. One
     * part of a split takes every core field from its original, as in the app
     * and `/api/v1`.
     */
    private function refuseUneditable(Request $request, Transaction $transaction): ?Response
    {
        if ($transaction->splits()->exists() && $request->hasAny(['category_id', 'amount'])) {
            return Response::error('This transaction is still split into lines the old way, so its category and amount belong to those lines until it is converted to the new split model. Notes and labels can still be changed.');
        }

        if ($transaction->isSplitPart()) {
            $error = TransactionWriteRules::lockedCoreFieldError($transaction, array_keys($request->all()), ', and label_transaction handles its labels');

            return $error === null ? null : Response::error($error);
        }

        $locked = array_values(array_filter(
            self::CORE_FIELDS,
            fn (string $field): bool => $request->has($field),
        ));

        if ($locked === [] || $transaction->source === TransactionSource::ManuallyCreated) {
            return null;
        }

        $fields = implode(', ', $locked);

        return Response::error("Only manually-created transactions can change {$fields}. This one came from a bank or import, so its core fields are locked; notes and category_id can still be edited here, and label_transaction handles its labels.");
    }
}
