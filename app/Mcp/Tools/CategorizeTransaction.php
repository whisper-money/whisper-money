<?php

namespace App\Mcp\Tools;

use App\Enums\CategorySource;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Set (or clear) the category of any transaction, bank/imported ones and split parts included. Marks the category as manually assigned. Pass category_id: null to remove the category.')]
class CategorizeTransaction extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the transaction to categorize.')->required(),
            'category_id' => $schema->string()->nullable()->description('Category id to assign, or null to remove the category.')->required(),
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

        $result = DB::transaction(function () use ($transaction, $categoryId): Transaction|Response {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if ($locked->splits()->exists()) {
                return Response::error('This transaction is still split into lines the old way, so its category belongs to those lines until it is converted to the new split model.');
            }

            $locked->category_id = $categoryId;
            $locked->category_source = $categoryId === null ? null : CategorySource::Manual;
            $locked->ai_confidence = null;
            $locked->categorized_by_rule_id = null;
            $locked->save();

            return $locked;
        });

        if ($result instanceof Response) {
            return $result;
        }

        return $this->json(['transaction' => $this->presentTransaction($result->refresh())]);
    }
}
