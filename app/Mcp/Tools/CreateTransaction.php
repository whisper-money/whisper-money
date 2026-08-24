<?php

namespace App\Mcp\Tools;

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ManualBalanceAdjuster;
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
            'amount' => $schema->integer()->description('Signed amount in minor units (cents). Negative = expense, positive = income.')->required(),
            'transaction_date' => $schema->string()->description('Transaction date, YYYY-MM-DD.')->required(),
            'currency_code' => $schema->string()->description('ISO 4217 currency code (3 letters). Defaults to the account currency.'),
            'category_id' => $schema->string()->description('Optional category id to assign.'),
            'creditor_name' => $schema->string()->description('Optional creditor (payee) name.'),
            'debtor_name' => $schema->string()->description('Optional debtor (payer) name.'),
            'notes' => $schema->string()->description('Optional free-text notes.'),
            'label_ids' => $schema->array()->items($schema->string())->description('Optional label ids to attach.'),
            'update_balance' => $schema->boolean()->description('When true, shift the account balance snapshots by this amount. Ignored on connected accounts, whose balances come from the bank. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'account_id' => ['required', 'string'],
            'description' => ['required', 'string'],
            'amount' => ['required', 'integer'],
            'transaction_date' => ['required', 'date'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'creditor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'debtor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $space = $this->resolveSpace($request, $user);
        $account = $this->accountInSpace($request, $space);
        $labels = $this->labelsInSpace($request, $space, 'label_ids');

        $categoryId = $request->filled('category_id')
            ? $this->categoryInSpace($request, $space)->id
            : null;

        $transaction = new Transaction([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'account_id' => $account->id,
            'category_id' => $categoryId,
            'category_source' => $categoryId === null ? null : CategorySource::Manual->value,
            'description' => $request->string('description')->toString(),
            'transaction_date' => $request->string('transaction_date')->toString(),
            'amount' => $request->integer('amount'),
            'currency_code' => $request->filled('currency_code')
                ? mb_strtoupper($request->string('currency_code')->toString())
                : $account->currency_code,
            'notes' => $request->filled('notes') ? $request->string('notes')->toString() : null,
            'creditor_name' => $request->filled('creditor_name') ? $request->string('creditor_name')->toString() : null,
            'debtor_name' => $request->filled('debtor_name') ? $request->string('debtor_name')->toString() : null,
            'source' => TransactionSource::ManuallyCreated->value,
        ]);
        $transaction->save();

        if ($labels->isNotEmpty()) {
            $transaction->labels()->sync($labels->pluck('id')->all());
        }

        // The adjuster no-ops on a connected account, whose balances belong to
        // the bank sync. Report what it actually did, otherwise the agent tells
        // the user a balance moved when it did not.
        $balanceUpdated = $request->boolean('update_balance')
            && app(ManualBalanceAdjuster::class)->applyCreatedTransaction($transaction->load('account'));

        return $this->json([
            'transaction' => $this->presentTransaction($transaction),
            'balance_updated' => $balanceUpdated,
        ]);
    }
}
