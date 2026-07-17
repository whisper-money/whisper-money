<?php

use App\Features\Mcp;
use App\Models\User;
use Laravel\Pennant\Feature;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * A user with the MCP rollout feature flag enabled.
 */
function mcpUser(): User
{
    $user = User::factory()->create();
    Feature::for($user)->activate(Mcp::class);

    return $user;
}

it('requires authentication to view the MCP page', function () {
    get(route('mcp.index'))->assertRedirect();
});

it('hides the MCP settings page when the feature flag is off', function () {
    actingAs(User::factory()->create())
        ->get(route('mcp.index'))
        ->assertNotFound();
});

it('renders the MCP settings page', function () {
    actingAs(mcpUser())
        ->get(route('mcp.index'))
        ->assertOk();
});

it('creates a read-only token and flashes the secret once', function () {
    $user = mcpUser();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => 'Claude Desktop', 'scope' => 'read'])
        ->assertRedirect(route('mcp.index'))
        ->assertSessionHas('mcp_token');

    $token = $user->tokens()->first();

    expect($token->name)->toBe('Claude Desktop');
    expect($token->abilities)->toBe(['mcp:read']);
});

it('creates a read & write token carrying the mcp:write ability', function () {
    $user = mcpUser();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => 'Claude Code', 'scope' => 'read_write'])
        ->assertRedirect(route('mcp.index'))
        ->assertSessionHas('mcp_token');

    expect($user->tokens()->first()->abilities)->toBe(['mcp:read', 'mcp:write']);
});

it('requires a token name', function () {
    actingAs(mcpUser())
        ->post(route('mcp.tokens.store'), ['name' => '', 'scope' => 'read'])
        ->assertSessionHasErrors('name');
});

it('requires a valid scope', function () {
    actingAs(mcpUser())
        ->post(route('mcp.tokens.store'), ['name' => 'Bad', 'scope' => 'admin'])
        ->assertSessionHasErrors('scope');

    actingAs(mcpUser())
        ->post(route('mcp.tokens.store'), ['name' => 'Missing'])
        ->assertSessionHasErrors('scope');
});

it('lets a free account create a token (gating happens at request time)', function () {
    config(['subscriptions.enabled' => true]);
    $user = mcpUser();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => 'Free', 'scope' => 'read'])
        ->assertSessionHas('mcp_token');

    expect($user->tokens()->count())->toBe(1);
});

it('revokes a token the user owns', function () {
    $user = mcpUser();
    $token = $user->createToken('X', ['mcp:read'])->accessToken;

    actingAs($user)
        ->delete(route('mcp.tokens.destroy', $token->id))
        ->assertRedirect(route('mcp.index'));

    expect($user->tokens()->count())->toBe(0);
});

it('cannot revoke another user\'s token', function () {
    $user = mcpUser();
    $other = User::factory()->create();
    $token = $other->createToken('X', ['mcp:read'])->accessToken;

    actingAs($user)
        ->delete(route('mcp.tokens.destroy', $token->id))
        ->assertForbidden();

    expect($other->tokens()->count())->toBe(1);
});

it('rotates a token, replacing the secret but keeping the scope', function () {
    $user = mcpUser();
    $token = $user->createToken('X', ['mcp:read'])->accessToken;

    actingAs($user)
        ->post(route('mcp.tokens.rotate', $token->id))
        ->assertSessionHas('mcp_token');

    $tokens = $user->tokens()->get();

    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->id)->not->toBe($token->id);
    expect($tokens->first()->abilities)->toBe(['mcp:read']);
});
