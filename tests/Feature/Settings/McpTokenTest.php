<?php

use App\Models\User;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

it('requires authentication to view the MCP page', function () {
    get(route('mcp.index'))->assertRedirect();
});

it('renders the MCP settings page', function () {
    actingAs(User::factory()->create())
        ->get(route('mcp.index'))
        ->assertOk();
});

it('creates a read-only token by default and flashes the secret once', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => 'Claude Desktop', 'scope' => 'read'])
        ->assertRedirect(route('mcp.index'))
        ->assertSessionHas('mcp_token');

    $token = $user->tokens()->first();

    expect($token->name)->toBe('Claude Desktop');
    expect($token->abilities)->toBe(['mcp:read']);
});

it('creates a read-write token when requested', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('mcp.tokens.store'), ['name' => 'CC', 'scope' => 'read_write']);

    expect($user->tokens()->first()->abilities)->toBe(['mcp:read', 'mcp:write']);
});

it('validates the token scope', function () {
    actingAs(User::factory()->create())
        ->post(route('mcp.tokens.store'), ['name' => 'X', 'scope' => 'admin'])
        ->assertSessionHasErrors('scope');
});

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
    $token = $user->createToken('X', ['mcp:read', 'mcp:write'])->accessToken;

    actingAs($user)
        ->post(route('mcp.tokens.rotate', $token->id))
        ->assertSessionHas('mcp_token');

    $tokens = $user->tokens()->get();

    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->id)->not->toBe($token->id);
    expect($tokens->first()->abilities)->toBe(['mcp:read', 'mcp:write']);
});
