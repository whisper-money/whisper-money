<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\LabelSpendingService;
use App\Services\PeriodComparator;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Expense spending by label for a date range, largest first, each against the previous period. Whole account; savings goal labels are left out.')]
class SpendingByLabel extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End date, YYYY-MM-DD.')->required(),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $period = PeriodComparator::fromRequest($request->validate(PeriodComparator::rules()));
        $previous = $period->previous();
        $currency = $this->reportingCurrency($user);

        return $this->json([
            'currency' => $currency,
            'previous_from' => $previous->from->toDateString(),
            'previous_to' => $previous->to->toDateString(),
            'labels' => array_map(fn (array $label): array => [
                'label_id' => $label['id'],
                'name' => $label['name'],
                'color' => $label['color'],
                'amount' => $label['amount'],
                'amount_formatted' => Money::format($label['amount'], $currency),
                'previous_amount' => $label['previous_amount'],
            ], app(LabelSpendingService::class)->comparedForPeriod($user->id, $period)),
        ]);
    }
}
