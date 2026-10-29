<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Edit a transaction; only the fields you pass change. Notes and category work on any transaction; the date on any but a split part; the other fields only on manual ones.')]
class UpdateTransaction extends WriteTool
{
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
            'category_id' => $schema->string()->description('New category id, or null to clear the category.'),
            'creditor_name' => $schema->string()->description('New creditor (payee) name.'),
            'debtor_name' => $schema->string()->description('New debtor (payer) name.'),
            'notes' => $schema->string()->description('New free-text notes, or null to clear them. Editable on any transaction, bank/imported ones and split parts included.'),
            'update_balance' => $schema->boolean()->description('When true and the amount/date/account changed, move the account balance snapshots accordingly. Ignored on connected accounts, whose balances come from the bank, and on credit cards that track a limit instead of a balance. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        $lockedFieldError = TransactionWriteRules::lockedCoreFieldError(
            $transaction,
            array_keys($request->all()),
            ', and label_transaction handles its labels',
        );

        if ($lockedFieldError !== null) {
            return Response::error($lockedFieldError);
        }

        $request->validate(TransactionWriteRules::updateRules());

        $changes = $request->all();

        if ($request->has('account_id')) {
            $changes['account_id'] = $this->accountInSpace($request, $space)->id;
        }

        if ($request->has('category_id')) {
            $changes['category_id'] = $request->filled('category_id') ? $this->categoryInSpace($request, $space)->id : null;
        }

        $written = app(TransactionWriter::class)->update($transaction, $changes, $request->boolean('update_balance'));

        return $this->json([
            'transaction' => $this->presentTransaction($written->transaction),
            'balance_updated' => $written->balanceUpdated,
        ]);
    }
}
