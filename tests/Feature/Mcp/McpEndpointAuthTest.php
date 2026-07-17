<?php

use App\Models\User;

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

it('accepts a token carrying the mcp:read ability', function () use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    // Auth + ability middleware pass, so the request reaches the MCP transport
    // (which answers the JSON-RPC envelope) rather than being rejected.
    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertOk();
});
