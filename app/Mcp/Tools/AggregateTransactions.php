<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\Transactions\TransactionAggregator;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Group and add up the user\'s transactions in a space by up to two of day, week, month, year, category, label, account and type: sum, count, avg, min and max per bucket and currency.')]
class AggregateTransactions extends McpTool
{
    /**
     * The metrics that are amounts, and so get a formatted twin.
     */
    private const AMOUNT_METRICS = ['sum', 'avg', 'min', 'max'];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Earliest transaction date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('Latest transaction date, YYYY-MM-DD.')->required(),
            'group_by' => $schema->array()->items($schema->string()->enum(TransactionAggregator::DIMENSIONS))
                ->description('Up to two dimensions, at most one of them a period (day, week, month, year). A week is named by its Monday. Omit to add up per currency only. Grouped by a period, every period of the range is returned, with zeros where nothing was booked.'),
            'metrics' => $schema->array()->items($schema->string()->enum(TransactionAggregator::METRICS))
                ->description('Defaults to sum and count. Amounts are signed (negative for money out) and avg/min/max are null on an empty bucket.'),
            'category_ids' => $schema->array()->items($schema->string())->description('Only these categories. Call list_categories to see valid ids.'),
            'include_subcategories' => $schema->boolean()->description('Whether category_ids also match their subcategories (default true).'),
            'label_ids' => $schema->array()->items($schema->string())->description('Only transactions with any of these labels; grouped by label, only these labels are listed. Call list_labels to see valid ids.'),
            'account_ids' => $schema->array()->items($schema->string())->description('Only these accounts. Call list_accounts to see valid ids.'),
            'types' => $schema->array()->items($schema->string()->enum(array_column(CategoryType::cases(), 'value')))
                ->description('Only these types. A transaction\'s type is its category\'s; an uncategorized one is expense when money went out, income otherwise.'),
            'query' => $schema->string()->description('Free text matched against description, creditor and debtor names.'),
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $validated = $request->validate(TransactionAggregator::rules());
        $space = $this->resolveSpace($request, $user);

        $aggregate = app(TransactionAggregator::class)->aggregate($user, $space, [
            ...$validated,
            'label_ids' => $this->labelsInSpace($request, $space, 'label_ids')->modelKeys(),
            'account_ids' => $this->ownRowsInSpace($request, $space, 'account_ids', Account::class, 'account', 'list_accounts')->modelKeys(),
            'category_ids' => $this->ownRowsInSpace($request, $space, 'category_ids', Category::class, 'category', 'list_categories')->modelKeys(),
        ]);

        // The formatted amounts save the agent scaling minor units itself (see
        // SpendingByCategory): each bucket carries its own currency.
        return $this->json([
            'space_id' => $space->id,
            ...$aggregate,
            'buckets' => array_map(fn (array $bucket): array => $this->withFormattedAmounts($bucket), $aggregate['buckets']),
        ]);
    }

    /**
     * @param  array<string, int|string|null>  $bucket
     * @return array<string, int|string|null>
     */
    private function withFormattedAmounts(array $bucket): array
    {
        foreach (self::AMOUNT_METRICS as $metric) {
            if (is_int($bucket[$metric] ?? null)) {
                $bucket["{$metric}_formatted"] = Money::format($bucket[$metric], (string) $bucket['currency']);
            }
        }

        return $bucket;
    }
}
