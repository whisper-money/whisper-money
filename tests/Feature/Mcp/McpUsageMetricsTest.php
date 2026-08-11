<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListSpaces;
use App\Models\McpToolCall;
use App\Models\User;

it('records a row per tool call, named after the tool', function () {
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)->tool(ListSpaces::class)->assertOk();
    WhisperMoneyServer::actingAs($user)->tool(ListSpaces::class)->assertOk();
    WhisperMoneyServer::actingAs($user)->tool(ListAccounts::class)->assertOk();

    expect(McpToolCall::query()->where('user_id', $user->id)->pluck('tool')->countBy()->all())
        ->toBe(['list_spaces' => 2, 'list_accounts' => 1]);
});

it('does not record calls rejected by the plan gate', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)->tool(ListSpaces::class)->assertHasErrors();

    expect(McpToolCall::query()->count())->toBe(0);
});

it('does not record a write rejected because the token is read-only', function () {
    $user = User::factory()->create();
    $user->withAccessToken($user->createToken('mcp', ['mcp:read'])->accessToken);

    WhisperMoneyServer::actingAs($user)
        ->tool(CreateLabel::class, ['name' => 'Trips', 'color' => 'blue'])
        ->assertHasErrors();

    expect(McpToolCall::query()->count())->toBe(0);
});

it('reports usage per tool, per user and per day', function () {
    $heavy = User::factory()->create(['email' => 'heavy@example.com']);
    $light = User::factory()->create(['email' => 'light@example.com']);

    McpToolCall::factory()->count(3)->create(['user_id' => $heavy->id, 'tool' => 'search_transactions']);
    McpToolCall::factory()->create(['user_id' => $light->id, 'tool' => 'get_net_worth']);
    McpToolCall::factory()->create([
        'user_id' => $light->id,
        'tool' => 'search_transactions',
        'created_at' => now()->subDays(90),
    ]);

    $this->artisan('stats:mcp-usage', ['--days' => 30])
        ->expectsOutputToContain('Calls: 4')
        ->expectsOutputToContain('search_transactions')
        ->expectsOutputToContain('heavy@example.com')
        ->expectsOutputToContain('light@example.com')
        ->assertSuccessful();
});

it('still attributes calls made by a user who deleted their account', function () {
    $user = User::factory()->create(['email' => 'churned@example.com']);
    McpToolCall::factory()->create(['user_id' => $user->id, 'tool' => 'get_net_worth']);
    $user->delete();

    // One substring per output line: two expectations matching the same line
    // would only consume the first (Mockery matches an expectation once).
    $this->artisan('stats:mcp-usage')
        ->expectsOutputToContain('churned@example.com (deleted)')
        ->assertSuccessful();
});

it('reports nothing when there is no usage in the window', function () {
    $this->artisan('stats:mcp-usage')
        ->expectsOutputToContain('No MCP tool calls')
        ->assertSuccessful();
});
