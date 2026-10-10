<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsAccounts;
use App\Models\Account;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s accounts in a space and whether each is bank-connected. Bookkeeping records only: no tool moves money between them or to anyone; the user does that at their bank.')]
class ListAccounts extends McpTool
{
    use PresentsAccounts;

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
            ->with(['bank:id,name', 'creditCardDetail'])
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => $this->presentAccount($account, $user));

        return $this->json([
            'space_id' => $space->id,
            'accounts' => $accounts,
        ]);
    }
}
