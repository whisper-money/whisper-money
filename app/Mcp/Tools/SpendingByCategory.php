<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\CategorySpendingService;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Expense spending rolled up by category for a date range, across the user\'s whole account. Without parent_category_id it returns root categories; pass one to drill into its children.')]
class SpendingByCategory extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End date, YYYY-MM-DD.')->required(),
            'parent_category_id' => $schema->string()->description('Drill into a parent category\'s children.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $spending = app(CategorySpendingService::class)->forPeriod(
            $user->id,
            Carbon::parse($request->string('from')->toString()),
            Carbon::parse($request->string('to')->toString()),
            $request->string('parent_category_id')->toString() ?: null,
        );

        $currency = $this->reportingCurrency($user);

        // The formatted amount saves the agent scaling minor units itself: given
        // only 219888 and EUR, ChatGPT once answered €219.89 instead of €2,198.88.
        return $this->json([
            'currency' => $currency,
            'categories' => $spending->map(fn (array $row): array => [
                ...$row,
                'amount_formatted' => Money::format($row['amount'], $currency),
            ])->values(),
        ]);
    }
}
