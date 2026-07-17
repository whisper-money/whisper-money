<?php

namespace App\Mcp\Tools;

use App\Enums\CategorySource;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Set (or clear) the category of any transaction, including bank/imported ones. Marks the category as manually assigned. Pass category_id: null to remove the category.')]
class CategorizeTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the transaction to categorize.')->required(),
            'category_id' => $schema->string()->description('Category id to assign, or null to remove the category.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        if (! $request->has('category_id')) {
            return Response::error('Provide category_id to set the category (or null to remove it).');
        }

        $space = $this->resolveSpace($request, $user);
        $transaction = $this->transactionInSpace($request, $space);

        $categoryId = $request->filled('category_id')
            ? $this->categoryInSpace($request, $space)->id
            : null;

        $transaction->category_id = $categoryId;
        $transaction->category_source = $categoryId === null ? null : CategorySource::Manual;
        $transaction->ai_confidence = null;
        $transaction->categorized_by_rule_id = null;
        $transaction->save();

        return $this->json(['transaction' => $this->presentTransaction($transaction->refresh())]);
    }
}
