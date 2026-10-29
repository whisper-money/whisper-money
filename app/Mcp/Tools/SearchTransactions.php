<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsTransactions;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Search and filter the user\'s transactions by text, category, account, label, date range and amount. Use it to analyse spending, or to find recurring charges by grouping results by merchant.')]
class SearchTransactions extends McpTool
{
    use PresentsTransactions;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Free text matched against description, creditor and debtor names.'),
            'account_id' => $schema->string()->description('Restrict to a single account id.'),
            'category_id' => $schema->string()->description('Restrict to a single category id.'),
            'label_ids' => $schema->array()->items($schema->string())->description('Restrict to transactions carrying any of these label ids. Call list_labels to see valid ids.'),
            'from' => $schema->string()->description('Earliest transaction date, YYYY-MM-DD.'),
            'to' => $schema->string()->description('Latest transaction date, YYYY-MM-DD.'),
            'min_amount' => $schema->integer()->description('Minimum signed amount in the account currency\'s minor units.'),
            'max_amount' => $schema->integer()->description('Maximum signed amount in the account currency\'s minor units.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Max rows to return (default 50).'),
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $request->validate([
            ...TransactionSearch::rules(),
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $space = $this->resolveSpace($request, $user);

        $labels = $this->labelsInSpace($request, $space, 'label_ids');

        $transactions = app(TransactionSearch::class)
            ->query($user, $space, [...$request->all(), 'label_ids' => $labels->modelKeys()])
            ->with(['account:id,name', 'category:id,name,type', 'labels:id,name'])
            ->orderByDesc('transaction_date')
            ->limit($request->integer('limit', 50))
            ->get()
            ->map(fn (Transaction $transaction): array => $this->presentTransaction($transaction));

        return $this->json([
            'space_id' => $space->id,
            'count' => $transactions->count(),
            'transactions' => $transactions,
        ]);
    }
}
