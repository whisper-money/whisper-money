<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\SavingsGoals\CurrentMonthSavings;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('This month across the running monthly savings goals: saved against target, each goal\'s month and how many goals met their target last month. Whole account.')]
class GetMonthlySavings extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function respond(Request $request, User $user): Response
    {
        $savings = app(CurrentMonthSavings::class)->forUser($user);
        $currency = $this->reportingCurrency($user);

        return $this->json([
            'currency' => $currency,
            ...$savings,
            'saved_formatted' => Money::format($savings['saved'], $currency),
            'target_formatted' => Money::format($savings['target'], $currency),
        ]);
    }
}
