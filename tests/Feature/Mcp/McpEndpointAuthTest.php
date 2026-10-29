<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListSpaces;
use App\Models\User;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

$rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

it('rejects the MCP endpoint without a token', function () use ($rpc) {
    postJson('/mcp', $rpc)->assertUnauthorized();
});

it('rejects a token without the mcp:read ability', function () use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('not-mcp', ['other'])->plainTextToken;

    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertForbidden();
});

it('rejects an API token, whatever its abilities', function (array $abilities) use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('api', $abilities)->plainTextToken;

    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertForbidden();
})->with([
    'read' => [['api:read']],
    'read & write' => [['api:read', 'api:write']],
]);

it('keeps accepting a legacy id|secret MCP token', function () use ($rpc) {
    $secret = Str::random(40);
    $legacy = User::factory()->create()->tokens()->create([
        'name' => 'Legacy',
        'token' => hash('sha256', $secret),
        'abilities' => ['mcp:read'],
    ]);

    withHeaders(['Authorization' => "Bearer {$legacy->id}|{$secret}"])
        ->postJson('/mcp', $rpc)
        ->assertOk();
});

it('accepts a token carrying the mcp:read ability', function () use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    // Auth + ability middleware pass, so the request reaches the MCP transport
    // (which answers the JSON-RPC envelope) rather than being rejected.
    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertOk();
});

it('rejects the demo account, whose credentials are public and data is shared', function () {
    config(['app.demo.email' => 'demo@example.test']);
    $user = User::factory()->create(['email' => 'demo@example.test']);
    $user->withAccessToken($user->createToken('mcp', ['mcp:read'])->accessToken);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertHasErrors();
});
