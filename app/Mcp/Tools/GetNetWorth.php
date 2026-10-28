<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\NetWorthAnalyticsService;
use App\Services\PeriodComparator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Net worth for a date range: the current total vs the previous period, plus each account\'s balance evolution. Set granularity to "monthly" (default) or "daily". Covers the whole account.')]
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
        $validated = $request->validate([
            ...PeriodComparator::rules(),
            'granularity' => ['sometimes', 'in:monthly,daily'],
        ]);

        $netWorth = app(NetWorthAnalyticsService::class);
        $period = PeriodComparator::fromRequest($validated);
        $daily = $request->string('granularity')->toString() === 'daily';

        return $this->json([
            'currency' => $this->reportingCurrency($user),
            'granularity' => $daily ? 'daily' : 'monthly',
            'current' => $netWorth->compared($user, $period),
            'evolution' => $netWorth->evolution($user, $period->from, $period->to, $daily),
        ]);
    }
}
