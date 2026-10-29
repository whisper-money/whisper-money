<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\CashflowAnalyticsService;
use App\Services\PeriodComparator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Full cashflow for a date range: income/expense/savings/investment summary (current vs previous, both with expense split fixed/variable/general), category sankey and monthly trend. Whole account.')]
class GetCashflow extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End date, YYYY-MM-DD. The summary\'s reserved and budgets count weekly, bi-weekly and monthly budgets for a range of up to 31 days, those plus quarterly ones for up to 92 days, and only yearly budgets for a longer one.')->required(),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $period = PeriodComparator::fromRequest($request->validate(PeriodComparator::rules()));

        return $this->json([
            'currency' => $this->reportingCurrency($user),
            ...app(CashflowAnalyticsService::class)->forRange($user, $period->from, $period->to),
        ]);
    }
}
