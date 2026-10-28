<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Accounts\AccountBalanceRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Record a balance snapshot on a non-connected (manual) account, replacing any snapshot for that date. Connected accounts are rejected: their balances come from the bank sync.')]
class CreateBalance extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of a non-connected (manual) account that is not a credit card.')->required(),
            'balance' => $schema->integer()->description('Balance in the account currency\'s minor units (100 = 1.00 EUR, 1 = 1 COP, 1 = 0.00000001 BTC).')->required(),
            'balance_date' => $schema->string()->description('Snapshot date, YYYY-MM-DD. Defaults to today.'),
            'invested_amount' => $schema->integer()->description('Optional invested amount in the account currency\'s minor units, for investment accounts.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $recorder = app(AccountBalanceRecorder::class);
        $request->validate($recorder->rules());

        $space = $this->resolveSpace($request, $user);
        $account = $this->balanceWritableAccount($request, $space);

        $balance = $recorder->record(
            $account,
            $request->integer('balance'),
            $this->nullableString($request, 'balance_date'),
            $request->filled('invested_amount') ? $request->integer('invested_amount') : null,
        );

        return $this->json([
            'balance' => [
                'id' => $balance->id,
                'account_id' => $balance->account_id,
                'balance_date' => $balance->balance_date->toDateString(),
                'balance' => $balance->balance,
                'invested_amount' => $balance->invested_amount,
            ],
        ]);
    }
}
