<?php

namespace App\Mcp\Tools;

use App\Http\Requests\Api\V1\AnalyticsRangeRequest;
use App\Models\User;
use App\Services\LabelSpendingService;
use App\Services\PeriodComparator;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
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
            'to' => $schema->string()->description('End date, YYYY-MM-DD, not before from. The range can touch at most '.AnalyticsRangeRequest::DEFAULT_MAX_MONTHS.' calendar months.')->required(),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $rules = PeriodComparator::rules();
        $period = PeriodComparator::fromRequest($request->validate([
            'from' => [...$rules['from'], 'date_format:Y-m-d'],
            'to' => [...$rules['to'], 'date_format:Y-m-d', 'after_or_equal:from'],
        ]));

        // The same cap as the API's: two queries per call, but no reason to
        // let an agent read further back than an API user can.
        if ($period->monthsTouched() > AnalyticsRangeRequest::DEFAULT_MAX_MONTHS) {
            throw ValidationException::withMessages(['to' => 'The range can touch at most '.AnalyticsRangeRequest::DEFAULT_MAX_MONTHS.' calendar months.']);
        }

        $previous = $period->previous();
        $currency = $this->reportingCurrency($user);
        $labels = app(LabelSpendingService::class)->comparedForPeriod($user->id, $period);
        $total = $labels[0]['total_amount'] ?? 0;

        return $this->json([
            'currency' => $currency,
            'total' => $total,
            'total_formatted' => Money::format($total, $currency),
            'previous_from' => $previous->from->toDateString(),
            'previous_to' => $previous->to->toDateString(),
            'labels' => array_map(fn (array $label): array => [
                'label_id' => $label['id'],
                'name' => $label['name'],
                'color' => $label['color'],
                'amount' => $label['amount'],
                'amount_formatted' => Money::format($label['amount'], $currency),
                'previous_amount' => $label['previous_amount'],
            ], $labels),
        ]);
    }
}
