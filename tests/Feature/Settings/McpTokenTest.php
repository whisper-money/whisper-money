<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

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

it('forbids framing the MCP settings page, which shows a new token', function () {
    actingAs(User::factory()->create())
        ->get(route('mcp.index'))
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeaderContains('Content-Security-Policy', "frame-ancestors 'none'");
});

it('creates a read-only token and flashes the secret once', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => 'Claude Desktop', 'scope' => 'read'])
        ->assertRedirect(route('mcp.index'))
        ->assertSessionHas('mcp_token');

    $token = $user->tokens()->first();

    expect($token->name)->toBe('Claude Desktop');
    expect($token->abilities)->toBe(['mcp:read']);
    expect(session('mcp_token'))->toStartWith('wm_mcp_');
});

it('creates a read & write token carrying the mcp:write ability', function () {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('mcp.tokens.store'), ['name' => 'Claude Code', 'scope' => 'read_write'])
        ->assertRedirect(route('mcp.index'))
        ->assertSessionHas('mcp_token');

    expect($user->tokens()->first()->abilities)->toBe(['mcp:read', 'mcp:write']);
    expect(session('mcp_token'))->toStartWith('wm_mcp_');
});

it('requires a token name', function () {
    actingAs(User::factory()->create())
        ->post(route('mcp.tokens.store'), ['name' => '', 'scope' => 'read'])
        ->assertSessionHasErrors('name');
});

it('requires a valid scope', function () {
    actingAs(User::factory()->create())
        ->post(route('mcp.tokens.store'), ['name' => 'Bad', 'scope' => 'admin'])
        ->assertSessionHasErrors('scope');

    actingAs(User::factory()->create())
        ->post(route('mcp.tokens.store'), ['name' => 'Missing'])
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
    $token = $user->createToken('X', ['mcp:read'])->accessToken;

    actingAs($user)
        ->post(route('mcp.tokens.rotate', $token->id))
        ->assertSessionHas('mcp_token');

    $tokens = $user->tokens()->get();

    expect($tokens)->toHaveCount(1);
    expect($tokens->first()->id)->not->toBe($token->id);
    expect($tokens->first()->abilities)->toBe(['mcp:read']);
});

it('lists MCP and wildcard tokens, never API tokens', function () {
    $user = User::factory()->create();
    $user->createToken('MCP', ['mcp:read']);
    $user->createToken('API', ['api:read']);
    $user->createToken('Wildcard');

    actingAs($user)
        ->get(route('mcp.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tokens', 2)
            ->where('tokens', fn ($tokens): bool => collect($tokens)->pluck('scope', 'name')->sortKeys()->all() === ['MCP' => 'read', 'Wildcard' => 'read_write']));
});

it('cannot rotate or revoke an API token from the MCP screen', function (string $method, string $route) {
    $user = User::factory()->create();
    $token = $user->createToken('API', ['api:read', 'api:write'])->accessToken;

    actingAs($user)
        ->{$method}(route($route, $token->id))
        ->assertNotFound();

    expect($user->tokens()->sole()->is($token))->toBeTrue();
})->with([
    'rotate' => ['post', 'mcp.tokens.rotate'],
    'revoke' => ['delete', 'mcp.tokens.destroy'],
]);

it('rotates a legacy id|secret token into a prefixed one', function () {
    $user = User::factory()->create();
    $legacy = $user->tokens()->create([
        'name' => 'Legacy',
        'token' => hash('sha256', 'secret'),
        'abilities' => ['mcp:read', 'mcp:write'],
    ]);

    actingAs($user)
        ->post(route('mcp.tokens.rotate', $legacy->id))
        ->assertSessionHas('mcp_token', fn (string $plain): bool => str_starts_with($plain, 'wm_mcp_'));

    expect($user->tokens()->sole()->abilities)->toBe(['mcp:read', 'mcp:write']);
});

it('refuses to rotate an expired token, so it cannot be revived', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Stale', ['mcp:read'], now()->subDay())->accessToken;

    actingAs($user)
        ->post(route('mcp.tokens.rotate', $token->id))
        ->assertSessionHasErrors('token')
        ->assertSessionMissing('mcp_token');

    expect($user->tokens()->sole()->is($token))->toBeTrue();
});
