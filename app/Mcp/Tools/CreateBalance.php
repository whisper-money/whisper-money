<?php

namespace App\Mcp\Tools;

use App\Models\AccountBalance;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Record an account balance snapshot on a non-connected (manual) account. Balance is an integer in minor units (cents). Replaces any existing snapshot for that date. Connected/bank accounts are read-only.')]
class CreateBalance extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of a non-connected (manual) account.')->required(),
            'balance' => $schema->integer()->description('Balance in minor units (cents).')->required(),
            'balance_date' => $schema->string()->description('Snapshot date, YYYY-MM-DD. Defaults to today.'),
            'invested_amount' => $schema->integer()->description('Optional invested amount in minor units (cents), for investment accounts.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'balance' => ['required', 'integer'],
            'balance_date' => ['sometimes', 'date'],
            'invested_amount' => ['sometimes', 'nullable', 'integer'],
        ]);

        $space = $this->resolveSpace($request, $user);
        $account = $this->writableAccount($request, $space);

        $balanceDate = $request->filled('balance_date')
            ? $request->string('balance_date')->toString()
            : now()->toDateString();

        $balance = AccountBalance::updateOrCreate(
            ['account_id' => $account->id, 'balance_date' => $balanceDate],
            [
                'balance' => $request->integer('balance'),
                ...$request->filled('invested_amount')
                    ? ['invested_amount' => $request->integer('invested_amount')]
                    : [],
            ],
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
