<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use App\Services\Transactions\TransactionAggregator;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Group and add up the transactions of a space (every member\'s, as search_transactions reads them) by up to two of day, week, month, year, category, label, account and type: sum, count, avg, min and max per bucket and currency. A split transaction counts by its lines.')]
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
                ->description('Defaults to sum and count. Amounts are signed (negative for money out) and avg/min/max are null on an empty bucket. Without a types filter, transfers, savings and investments are added in too.'),
            'category_ids' => $schema->array()->items($schema->string())->description('Only these categories; a split transaction adds only its lines in them. Call list_categories to see valid ids.'),
            'include_subcategories' => $schema->boolean()->description('Whether category_ids also match their subcategories (default true).'),
            'label_ids' => $schema->array()->items($schema->string())->description('Only transactions with any of these labels, every line of a split one; grouped by label, only these labels are listed. Call list_labels to see valid ids.'),
            'account_ids' => $schema->array()->items($schema->string())->description('Only these accounts. Call list_accounts to see valid ids.'),
            'types' => $schema->array()->items($schema->string()->enum(array_column(CategoryType::cases(), 'value')))
                ->description('Only these types. A transaction\'s type is its category\'s (a split line\'s, its own category\'s); an uncategorized one is expense when money went out, income otherwise.'),
            'query' => $schema->string()->description('Free text matched against description, notes, creditor and debtor names.'),
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $validated = Validator::validate(
            [...$request->all(), ...TransactionAggregator::normalize($request->all())],
            TransactionAggregator::rules(),
        );
        $space = $this->resolveSpace($request, $user);

        $aggregate = app(TransactionAggregator::class)->aggregate($user, $space, [
            ...$validated,
            'label_ids' => $this->rowsInSpace($space, $this->listedIds($validated, 'label_ids'), 'label_ids', Label::class, 'label', 'list_labels')->modelKeys(),
            'account_ids' => $this->rowsInSpace($space, $this->listedIds($validated, 'account_ids'), 'account_ids', Account::class, 'account', 'list_accounts')->modelKeys(),
            'category_ids' => $this->rowsInSpace($space, $this->listedIds($validated, 'category_ids'), 'category_ids', Category::class, 'category', 'list_categories')->modelKeys(),
        ], everyMember: true, rawAccountNames: true);

        // The formatted amounts save the agent scaling minor units itself (see
        // SpendingByCategory): each bucket carries its own currency.
        return $this->json([
            'space_id' => $space->id,
            ...$aggregate,
            'buckets' => array_map(fn (array $bucket): array => $this->withFormattedAmounts($bucket), $aggregate['buckets']),
        ]);
    }

    /**
     * The ids validated under $key, de-duplicated.
     *
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function listedIds(array $validated, string $key): array
    {
        return array_values(array_unique(array_map(strval(...), $validated[$key] ?? [])));
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
