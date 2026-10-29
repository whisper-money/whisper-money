<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\ListAccounts;
use App\Models\Account;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;

/*
 * The MCP server mirrors the web app's permissions: inside a shared space a
 * member reads, changes and references only their own rows.
 */

/**
 * A shared space owned by $owner that $member joined.
 */
function mcpSharedSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

/**
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 */
function callOwnRowsTool(User $user, string $tool, array $arguments): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

it('lists only the caller\'s own accounts of a shared space', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $mine = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'name' => 'Mine']);
    Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);

    callOwnRowsTool($user, ListAccounts::class, ['space' => $space->id])
        ->assertOk()
        ->assertSee($mine->id)
        ->assertDontSee('Theirs');
});

it('refuses a balance on another member\'s account of a shared space', function () {
    $user = User::factory()->create();
    $owner = User::factory()->create();
    $space = mcpSharedSpace($user, $owner);
    $theirs = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'type' => 'checking']);
    $mine = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => 'checking']);

    callOwnRowsTool($user, CreateBalance::class, ['account_id' => $theirs->id, 'space' => $space->id, 'balance' => 1_000])
        ->assertHasErrors(["No account with id {$theirs->id} in space {$space->id}."]);

    callOwnRowsTool($user, CreateBalance::class, ['account_id' => $mine->id, 'space' => $space->id, 'balance' => 1_000])
        ->assertOk();

    expect($theirs->balances()->count())->toBe(0)
        ->and($mine->balances()->count())->toBe(1);
});
