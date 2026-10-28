<?php

use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\Client;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withHeaders;

/**
 * Connect a new client to the user through the real OAuth flow.
 *
 * @return array{0: Client, 1: string}
 */
function connectApp(User $user, string $name, string $scope): array
{
    $client = oauthClient($name);

    return [$client, issueOAuthToken($user, [$scope], $client)];
}

/**
 * A second live token (and refresh token) for the client, as signing in to
 * the same app from another device leaves behind.
 */
function connectAgain(Client $client): void
{
    $token = Token::query()->where('client_id', $client->id)->firstOrFail();
    $copy = $token->replicate()->forceFill(['id' => Str::random(80)]);
    $copy->save();

    RefreshToken::query()->where('access_token_id', $token->id)->firstOrFail()
        ->replicate()->forceFill(['id' => Str::random(80), 'access_token_id' => $copy->id])->save();
}

it('lists each screen\'s connected apps only', function (string $route, string $scope, string $label, string $listed) {
    $user = User::factory()->create();
    connectApp($user, 'Budget Sync', 'api:use');
    connectApp($user, 'Claude', 'mcp:use');
    connectApp(User::factory()->create(), 'Someone else\'s app', $scope);

    actingAs($user)->get(route($route))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('connectedApps', 1)
            ->where('connectedApps.0.name', $listed)
            ->where('connectedApps.0.scopes', [$label])
            ->where('connectedApps.0.redirect', 'https://claude.ai')
            ->whereNot('connectedApps.0.connected_at', null));
})->with([
    'Settings → API' => ['api-tokens.index', 'api:use', 'API', 'Budget Sync'],
    'Settings → AI Connector' => ['mcp.index', 'mcp:use', 'AI Connector', 'Claude'],
]);

it('groups an app\'s tokens into one row and shows when the API last saw it', function () {
    $user = User::factory()->create();
    [$client] = connectApp($user, 'Budget Sync', 'api:use');
    connectAgain($client);
    ApiRequest::factory()->for($user)->create(['oauth_client_id' => $client->id, 'created_at' => Carbon::parse('2026-10-20 10:00:00')]);
    ApiRequest::factory()->for($user)->create(['oauth_client_id' => $client->id, 'created_at' => Carbon::parse('2026-10-25 10:00:00')]);

    actingAs($user)->get(route('api-tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('connectedApps', 1)
            ->where('connectedApps.0.id', $client->id)
            ->where('connectedApps.0.last_used_at', Carbon::parse('2026-10-25 10:00:00')->toIso8601String()));
});

it('leaves out apps whose access can no longer be used or refreshed', function () {
    $user = User::factory()->create();
    connectApp($user, 'Revoked', 'api:use');
    Token::query()->update(['revoked' => true]);
    connectApp($user, 'Expired', 'api:use');
    Token::query()->where('revoked', false)->update(['expires_at' => now()->subDay()]);
    RefreshToken::query()->update(['expires_at' => now()->subDay()]);

    actingAs($user)->get(route('api-tokens.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('connectedApps', 0));
});

it('revokes all of an app\'s tokens and refresh tokens, cutting off its access', function () {
    $user = User::factory()->create();
    [$client, $token] = connectApp($user, 'Budget Sync', 'api:use');
    connectAgain($client);

    actingAs($user)->delete(route('api-tokens.apps.destroy', $client->id))->assertRedirect();

    expect(Token::query()->where('client_id', $client->id)->where('revoked', false)->exists())->toBeFalse()
        ->and(RefreshToken::query()->where('revoked', false)->exists())->toBeFalse();

    forgetSignedInUser();
    withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/v1/spaces')->assertUnauthorized();
});

it('asks for consent again after an app is revoked', function () {
    $user = User::factory()->create();
    [$client] = connectApp($user, 'Budget Sync', 'api:use');
    actingAs($user)->delete(route('api-tokens.apps.destroy', $client->id));
    [, $challenge] = pkcePair();

    requestOAuthConsent($user, $client, ['api:use'], $challenge)->assertOk()->assertSee('id="authorizeForm"', false);
});

it('revokes only the screen\'s own scope from an app connected to both', function () {
    $user = User::factory()->create();
    $client = oauthClient('Both');
    issueOAuthToken($user, ['api:use'], $client);
    $mcpToken = issueOAuthToken($user, ['mcp:use'], $client);

    actingAs($user)->delete(route('mcp.apps.destroy', $client->id))->assertRedirect();

    expect(Token::query()->where('revoked', false)->sole()->scopes)->toBe(['api:use']);

    forgetSignedInUser();
    withHeaders(['Authorization' => "Bearer {$mcpToken}"])
        ->postJson('/mcp/oauth', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertUnauthorized();
});

it('never touches another user\'s app or another screen\'s scope', function () {
    $user = User::factory()->create();
    [$othersClient] = connectApp(User::factory()->create(), 'Someone else\'s app', 'api:use');
    [$mcpClient] = connectApp($user, 'Claude', 'mcp:use');

    actingAs($user)->delete(route('api-tokens.apps.destroy', $othersClient->id))->assertRedirect();
    actingAs($user)->delete(route('api-tokens.apps.destroy', $mcpClient->id))->assertRedirect();

    expect(Token::query()->where('revoked', true)->exists())->toBeFalse();
});

it('lets a lapsed subscriber disconnect an app', function () {
    $user = User::factory()->create();
    [$client] = connectApp($user, 'Budget Sync', 'api:use');
    config(['subscriptions.enabled' => true]);

    actingAs($user)->delete(route('api-tokens.apps.destroy', $client->id))->assertRedirect();

    expect(Token::query()->where('revoked', false)->exists())->toBeFalse();
});
