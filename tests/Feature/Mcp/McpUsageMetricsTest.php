<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListSpaces;
use App\Models\McpToolCall;
use App\Models\User;

it('records a row per tool call, named after the tool', function () {
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)->tool(ListSpaces::class)->assertOk();
    WhisperMoneyServer::actingAs($user)->tool(ListSpaces::class)->assertOk();
    WhisperMoneyServer::actingAs($user)->tool(ListAccounts::class)->assertOk();

    expect(McpToolCall::query()->where('user_id', $user->id)->pluck('tool')->all())
        ->toBe(['list_spaces', 'list_spaces', 'list_accounts']);
});

it('does not record calls rejected by the plan gate', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)->tool(ListSpaces::class)->assertHasErrors();

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

it('reports nothing when there is no usage in the window', function () {
    $this->artisan('stats:mcp-usage')
        ->expectsOutputToContain('No MCP tool calls')
        ->assertSuccessful();
});
