<?php

namespace App\Mcp\Tools;

use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s accounts in a space, including whether each is connected to a bank/provider (connected accounts are read-only).')]
class ListAccounts extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);

        $accounts = Account::query()
            ->forSpace($space)
            ->with('bank:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'currency' => $account->currency_code,
                'bank' => $account->bank?->name,
                'is_connected' => $account->isConnected(),
            ]);

        return $this->json([
            'space_id' => $space->id,
            'accounts' => $accounts,
        ]);
    }
}
