<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a manual transaction on any account, bank-connected ones included. Amount is signed: negative for an expense, positive for income. Nothing dedups it, so add only what the bank will not sync.')]
class CreateTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of the account to add the transaction to.')->required(),
            'description' => $schema->string()->description('Human-readable description.')->required(),
            'amount' => $schema->integer()->description('Signed amount in the minor units of currency_code, which defaults to the account currency. Negative = expense, positive = income.')->required(),
            'transaction_date' => $schema->string()->description('Transaction date, YYYY-MM-DD.')->required(),
            'currency_code' => $schema->string()->description('ISO 4217 currency code (3 letters). Defaults to the account currency.'),
            'category_id' => $schema->string()->description('Optional category id to assign.'),
            'creditor_name' => $schema->string()->description('Optional creditor (payee) name.'),
            'debtor_name' => $schema->string()->description('Optional debtor (payer) name.'),
            'notes' => $schema->string()->description('Optional free-text notes.'),
            'label_ids' => $schema->array()->items($schema->string())->description('Optional label ids to attach.'),
            'update_balance' => $schema->boolean()->description('When true, shift the account balance snapshots by this amount. Ignored on connected accounts, whose balances come from the bank, and on credit cards that track a limit instead of a balance. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(TransactionWriteRules::createRules());

        $space = $this->resolveSpace($request, $user);
        $account = $this->accountInSpace($request, $space);
        $labels = $this->labelsInSpace($request, $space, 'label_ids');

        $categoryId = $request->filled('category_id')
            ? $this->categoryInSpace($request, $space)->id
            : null;

        $written = app(TransactionWriter::class)->create(
            $user,
            $space,
            $account,
            [...$request->all(), 'category_id' => $categoryId],
            $labels->modelKeys(),
            $request->boolean('update_balance'),
        );

        return $this->json([
            'transaction' => $this->presentTransaction($written->transaction),
            'balance_updated' => $written->balanceUpdated,
        ]);
    }
}
