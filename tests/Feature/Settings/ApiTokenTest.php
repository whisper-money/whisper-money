<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Laravel\Sanctum\PersonalAccessToken;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

it('requires authentication to view the API page', function () {
    get(route('api-tokens.index'))->assertRedirect();
});

it('renders the API page with the base URL', function () {
    actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/api')
            ->where('baseUrl', url('/api/v1'))
            ->where('docsUrl', route('documentation.index'))
            ->where('newToken', null)
            ->has('tokens', 0));
});

it('hides the API page from both shared accounts', function (string $account) {
    config(["app.{$account}.email" => "{$account}@example.test"]);

    actingAs(User::factory()->create(['email' => "{$account}@example.test"]))
        ->get(route('api-tokens.index'))
        ->assertNotFound();
})->with(['demo', 'press']);

it('refuses token changes from both shared accounts', function (string $account) {
    config(["app.{$account}.email" => "{$account}@example.test"]);
    $user = User::factory()->create(['email' => "{$account}@example.test"]);
    $token = $user->createToken('API', ['api:read'])->accessToken;

    actingAs($user)->post(route('api-tokens.store'), ['name' => 'Shared', 'scope' => 'read'])
        ->assertSessionHasErrors('demo');
    actingAs($user)->post(route('api-tokens.rotate', $token->id))
        ->assertSessionHasErrors('demo');
    actingAs($user)->delete(route('api-tokens.destroy', $token->id))
        ->assertSessionHasErrors('demo');

    expect($user->tokens()->sole()->is($token))->toBeTrue();
})->with(['demo', 'press']);

it('creates a token for the scope picked and flashes the secret once', function (string $scope, array $abilities) {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Script', 'scope' => $scope])
        ->assertRedirect(route('api-tokens.index'))
        ->assertSessionHas('api_token', fn (string $plain): bool => str_starts_with($plain, 'wm_api_'));

    $token = $user->tokens()->sole();

    expect($token->name)->toBe('Script');
    expect($token->abilities)->toBe($abilities);
})->with([
    'read-only' => ['read', ['api:read']],
    'read & write' => ['read_write', ['api:read', 'api:write']],
]);

it('sets the expiry picked, 90 days when none is sent', function (?string $expiry, ?int $days) {
    Carbon::setTestNow('2026-10-27 12:00:00');
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('api-tokens.store'), array_filter(['name' => 'Script', 'scope' => 'read', 'expiry' => $expiry]))
        ->assertSessionHasNoErrors();

    expect($user->tokens()->sole()->expires_at?->toDateTimeString())
        ->toBe($days === null ? null : now()->addDays($days)->toDateTimeString());
})->with([
    'default' => [null, 90],
    '30 days' => ['30_days', 30],
    '90 days' => ['90_days', 90],
    '1 year' => ['1_year', 365],
    'never' => ['never', null],
]);

it('rejects a token without a name, or with an unknown scope or expiry', function (array $payload, string $field) {
    actingAs(User::factory()->create())
        ->post(route('api-tokens.store'), $payload)
        ->assertSessionHasErrors($field);
})->with([
    'empty name' => [['name' => '', 'scope' => 'read'], 'name'],
    'unknown scope' => [['name' => 'Bad', 'scope' => 'admin'], 'scope'],
    'unknown expiry' => [['name' => 'Bad', 'scope' => 'read', 'expiry' => '2_days'], 'expiry'],
]);

it('requires a Pro plan to create or rotate a token, but not to revoke one', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();
    $token = $user->createToken('Lapsed', ['api:read'])->accessToken;

    actingAs($user)->post(route('api-tokens.store'), ['name' => 'Free', 'scope' => 'read'])
        ->assertForbidden();
    actingAs($user)->post(route('api-tokens.rotate', $token->id))
        ->assertForbidden();

    expect($user->tokens()->sole()->is($token))->toBeTrue();

    actingAs($user)->delete(route('api-tokens.destroy', $token->id))
        ->assertRedirect(route('api-tokens.index'));

    expect($user->tokens()->count())->toBe(0);
});

it('lets a Pro account create a token', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->subscribed()->create();

    actingAs($user)
        ->post(route('api-tokens.store'), ['name' => 'Pro', 'scope' => 'read'])
        ->assertSessionHas('api_token');
});

it('rotates a token keeping its name, abilities and lifetime', function () {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $user = User::factory()->create();
    $token = $user->createToken('Script', ['api:read', 'api:write'], now()->addDays(30))->accessToken;

    Carbon::setTestNow('2026-01-20 00:00:00');

    actingAs($user)
        ->post(route('api-tokens.rotate', $token->id))
        ->assertSessionHas('api_token', fn (string $plain): bool => str_starts_with($plain, 'wm_api_'));

    $fresh = $user->tokens()->sole();

    expect($fresh->id)->not->toBe($token->id);
    expect($fresh->name)->toBe('Script');
    expect($fresh->abilities)->toBe(['api:read', 'api:write']);
    expect($fresh->expires_at->toDateTimeString())->toBe('2026-02-19 00:00:00');
});

it('rotates a token that never expires into one that never expires', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Forever', ['api:read'])->accessToken;

    actingAs($user)->post(route('api-tokens.rotate', $token->id));

    expect($user->tokens()->sole()->expires_at)->toBeNull();
});

it('cannot rotate or revoke another user\'s token', function (string $method, string $route) {
    $other = User::factory()->create();
    $token = $other->createToken('X', ['api:read'])->accessToken;

    actingAs(User::factory()->create())
        ->{$method}(route($route, $token->id))
        ->assertForbidden();

    expect($other->tokens()->sole()->is($token))->toBeTrue();
})->with([
    'rotate' => ['post', 'api-tokens.rotate'],
    'revoke' => ['delete', 'api-tokens.destroy'],
]);

it('lists only API tokens, with their expiry', function () {
    Carbon::setTestNow('2026-10-27 12:00:00');
    $user = User::factory()->create();
    $user->createToken('Read', ['api:read'], now()->addDays(90));
    $user->createToken('Write', ['api:read', 'api:write']);
    $user->createToken('Old', ['api:read'], now()->subDay());
    $user->createToken('MCP', ['mcp:read']);
    $user->createToken('Wildcard');

    actingAs($user)
        ->get(route('api-tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tokens', 3)
            ->where('tokens', fn ($tokens): bool => collect($tokens)
                ->mapWithKeys(fn (array $token): array => [$token['name'] => [$token['scope'], $token['expires_at'], $token['expired']]])
                ->sortKeys()
                ->all() === [
                    'Old' => ['read', now()->subDay()->toIso8601String(), true],
                    'Read' => ['read', now()->addDays(90)->toIso8601String(), false],
                    'Write' => ['read_write', null, false],
                ]));
});

it('cannot rotate or revoke an MCP or wildcard token from the API screen', function (array $abilities, string $method, string $route) {
    $user = User::factory()->create();
    $token = $user->createToken('Other', $abilities)->accessToken;

    actingAs($user)
        ->{$method}(route($route, $token->id))
        ->assertNotFound();

    expect($user->tokens()->sole()->is($token))->toBeTrue();
})->with([
    'MCP' => [['mcp:read', 'mcp:write']],
    'wildcard' => [['*']],
])->with([
    'rotate' => ['post', 'api-tokens.rotate'],
    'revoke' => ['delete', 'api-tokens.destroy'],
]);

it('issues a token that authenticates at the API and is refused by the MCP server', function () {
    $user = User::factory()->create();

    actingAs($user)->post(route('api-tokens.store'), ['name' => 'Script', 'scope' => 'read_write']);
    $plain = session('api_token');

    app('auth')->forgetGuards();

    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson('/api/v1/spaces')
        ->assertOk();

    app('auth')->forgetGuards();

    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertForbidden();
});

it('refuses to rotate an expired token, so it cannot be revived', function () {
    Carbon::setTestNow('2026-01-01 00:00:00');
    $user = User::factory()->create();
    $token = $user->createToken('Stale', ['api:read'], now()->addDays(30))->accessToken;

    Carbon::setTestNow('2026-03-01 00:00:00');

    actingAs($user)
        ->post(route('api-tokens.rotate', $token->id))
        ->assertSessionHasErrors('token')
        ->assertSessionMissing('api_token');

    expect($user->tokens()->sole()->is($token))->toBeTrue();
});

it('keeps only the old token when rotating it fails halfway', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Script', ['api:read'])->accessToken;
    PersonalAccessToken::deleting(fn () => throw new RuntimeException('Delete failed.'));

    expect(fn () => actingAs($user)->withoutExceptionHandling()->post(route('api-tokens.rotate', $token->id)))
        ->toThrow(RuntimeException::class, 'Delete failed.');

    expect($user->tokens()->sole()->is($token))->toBeTrue();
});
