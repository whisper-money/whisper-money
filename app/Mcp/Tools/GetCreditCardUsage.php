<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;
use App\Support\Money;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('How much of each live credit card is in use: still to be charged, what is left of the limit, and day by day since the window opened. Whole account.')]
class GetCreditCardUsage extends McpTool
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
        $cards = app(CreditCardStatementService::class)->liveUsageFor($user);

        return $this->json([
            'credit_cards' => array_map(fn (array $card): array => [
                'account_id' => $card['account']->id,
                'name' => $card['account']->name,
                'currency' => $card['account']->currency_code,
                ...$card['usage'],
                'used_formatted' => Money::format($card['usage']['used'], $card['account']->currency_code),
            ], $cards),
        ]);
    }
}
