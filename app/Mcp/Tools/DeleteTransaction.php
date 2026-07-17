<?php

namespace App\Mcp\Tools;

use App\Enums\TransactionSource;
use App\Models\User;
use App\Services\ManualBalanceAdjuster;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a manually-created transaction. Only manual transactions can be deleted; bank/imported ones cannot.')]
class DeleteTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the manually-created transaction to delete.')->required(),
            'update_balance' => $schema->boolean()->description('When true, reverse this transaction from the manual account balance snapshots. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        if ($transaction->source !== TransactionSource::ManuallyCreated) {
            return Response::error('Only manually-created transactions can be deleted. This one came from a bank or import.');
        }

        if ($request->boolean('update_balance')) {
            app(ManualBalanceAdjuster::class)->reverseDeletedTransaction($transaction);
        }

        $transaction->delete();

        return $this->json(['deleted' => true, 'id' => $transaction->id]);
    }
}
