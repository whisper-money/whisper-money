<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\CashflowAnalyticsController;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The full cashflow picture for a date range as JSON, mirroring the app\'s cashflow screen: income/expense/savings/investment summary (current vs previous), the income-vs-expense category flow (sankey), and the monthly trend. Amounts are in minor units (cents). Covers the user\'s whole account.')]
class GetCashflow extends McpTool
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
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $controller = app(CashflowAnalyticsController::class);
        $range = ['from' => $request->string('from')->toString(), 'to' => $request->string('to')->toString()];

        return $this->json([
            'summary' => $this->callController($controller, 'summary', $user, $range),
            'sankey' => $this->callController($controller, 'sankey', $user, $range),
            'trend' => $this->callController($controller, 'trend', $user, $range),
        ]);
    }
}
