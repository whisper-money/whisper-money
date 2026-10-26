<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('requires authentication to view the MCP page', function () {
    get(route('mcp.index'))->assertRedirect();
});

it('hides the MCP settings page from the demo account', function () {
    config(['app.demo.email' => 'demo@example.test']);

    actingAs(User::factory()->create(['email' => 'demo@example.test']))
        ->get(route('mcp.index'))
        ->assertNotFound();
});

it('renders the MCP settings page', function () {
    actingAs(User::factory()->create())
        ->get(route('mcp.index'))
        ->assertOk();
});

it('creates a token for the scope picked and flashes the secret once', function (string $name, string $scope, array $abilities) {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => $name, 'scope' => $scope])
        ->assertRedirect(route('mcp.index'))
        ->assertSessionHas('mcp_token');

    $token = $user->tokens()->first();

    expect($token->name)->toBe($name);
    expect($token->abilities)->toBe($abilities);
})->with([
    'read-only' => ['Claude Desktop', 'read', ['mcp:read']],
    'read & write, carrying the mcp:write ability' => ['Claude Code', 'read_write', ['mcp:read', 'mcp:write']],
]);

it('rejects a token without a name or a valid scope', function (array $payload, string $field) {
    actingAs(User::factory()->create())
        ->post(route('mcp.tokens.store'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'empty name' => [['name' => '', 'scope' => 'read'], 'name'],
    'unknown scope' => [['name' => 'Bad', 'scope' => 'admin'], 'scope'],
    'missing scope' => [['name' => 'Missing'], 'scope'],
]);

it('lets a free account create a token (gating happens at request time)', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => 'Free', 'scope' => 'read'])
        ->assertSessionHas('mcp_token');

    expect($user->tokens()->count())->toBe(1);
});

it('revokes a token the user owns', function () {
    $user = User::factory()->create();
    $token = $user->createToken('X', ['mcp:read'])->accessToken;

    actingAs($user)
        ->delete(route('mcp.tokens.destroy', $token->id))
        ->assertRedirect(route('mcp.index'));

    expect($user->tokens()->count())->toBe(0);
});

it('cannot revoke another user\'s token', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $token = $other->createToken('X', ['mcp:read'])->accessToken;

    actingAs($user)
        ->delete(route('mcp.tokens.destroy', $token->id))
        ->assertForbidden();

    expect($other->tokens()->count())->toBe(1);
});

it('rotates a token, replacing the secret but keeping the scope', function () {
    $user = User::factory()->create();
    $token = $user->createToken('X', ['mcp:read'])->accessToken;

    actingAs($user)
        ->post(route('mcp.tokens.rotate', $token->id))
        ->assertSessionHas('mcp_token');

    $tokens = $user->tokens()->get();

    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->id)->not->toBe($token->id);
    expect($tokens->first()->abilities)->toBe(['mcp:read']);
});
