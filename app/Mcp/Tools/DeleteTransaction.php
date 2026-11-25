<?php

namespace App\Mcp\Tools;

use App\Enums\TransactionSource;
use App\Models\User;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a manually-created transaction. Only manual transactions can be deleted; bank/imported ones cannot, nor one part of a split (merge the split first).')]
class DeleteTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the manually-created transaction to delete.')->required(),
            'update_balance' => $schema->boolean()->description('When true, reverse this transaction from the account balance snapshots. Ignored on connected accounts, whose balances come from the bank, and on credit cards that track a limit instead of a balance. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        $refusal = $transaction->source === TransactionSource::ManuallyCreated
            ? TransactionWriteRules::deletionError($transaction)
            : 'Only manually-created transactions can be deleted. This one came from a bank or import.';

        if ($refusal !== null) {
            return Response::error($refusal);
        }

        $balanceUpdated = app(TransactionWriter::class)->delete($transaction, $request->boolean('update_balance'));

        return $this->json([
            'deleted' => true,
            'id' => $transaction->id,
            'balance_updated' => $balanceUpdated,
        ]);
    }
}
