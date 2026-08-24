<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\TransactionSplitter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Merge a split back into the single transaction it came from. Pass any one of its parts. The category, labels and notes on every part are lost.')]
class MergeTransactionSplits extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of any part of the split. Parts carry a split_parent_id; transactions without one are not part of a split.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $part = $this->transactionInSpace($request, $space);

        $original = app(TransactionSplitter::class)->merge($part);

        return $this->json([
            'space_id' => $space->id,
            'merged' => true,
            'transaction' => $this->presentTransaction($original),
        ]);
    }
}
