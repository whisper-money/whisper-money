<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Net worth for a date range as JSON: the current total vs the previous period, plus the per-account balance evolution over time. Set granularity to "monthly" (default) or "daily". Amounts are in minor units (cents). Covers the user\'s whole account.')]
class GetNetWorth extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End date, YYYY-MM-DD.')->required(),
            'granularity' => $schema->string()->enum(['monthly', 'daily'])->description('Evolution granularity (default monthly).'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
            'granularity' => ['sometimes', 'in:monthly,daily'],
        ]);

        $controller = app(DashboardAnalyticsController::class);
        $range = ['from' => $request->string('from')->toString(), 'to' => $request->string('to')->toString()];
        $daily = $request->string('granularity')->toString() === 'daily';

        return $this->json([
            'granularity' => $daily ? 'daily' : 'monthly',
            'current' => $this->callController($controller, 'netWorth', $user, $range),
            'evolution' => $this->callController(
                $controller,
                $daily ? 'netWorthDailyEvolution' : 'netWorthEvolution',
                $user,
                $range,
            ),
        ]);
    }
}
