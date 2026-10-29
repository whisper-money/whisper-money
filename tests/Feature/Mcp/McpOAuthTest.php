<?php

use App\Models\Label;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Exception\OAuthServerException;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/*
|--------------------------------------------------------------------------
| Discovery (RFC 9728 / RFC 8414)
|--------------------------------------------------------------------------
*/

it('serves protected resource metadata for the OAuth MCP endpoint', function () {
    $response = get('/.well-known/oauth-protected-resource/mcp/oauth')->assertOk();

    expect($response->json('resource'))->toEndWith('/mcp/oauth');
    expect($response->json('authorization_servers'))->not->toBeEmpty();
    expect($response->json('scopes_supported'))->toBe(['mcp:use']);
});

it('serves authorization server metadata advertising PKCE and both OAuth scopes', function () {
    $json = get('/.well-known/oauth-authorization-server')->assertOk()->json();

    expect($json['response_types_supported'])->toBe(['code']);
    expect($json['code_challenge_methods_supported'])->toBe(['S256']);
    expect($json['scopes_supported'])->toBe(['mcp:use', 'api:use']);
    expect($json['grant_types_supported'])->toBe(['authorization_code', 'refresh_token']);
    expect($json['authorization_endpoint'])->toContain('oauth/authorize');
    expect($json['token_endpoint'])->toContain('oauth/token');
    expect($json['registration_endpoint'])->toContain('oauth/register');
});

it('runs the whole authorization server on the configured dedicated host', function () {
    config()->set('mcp.authorization_server', 'https://oauth.whisper.money');

    // Protected-resource metadata (served from the app origin) points clients at the
    // dedicated host, while the protected resource itself stays on the app origin.
    $resource = get('/.well-known/oauth-protected-resource/mcp/oauth')->assertOk()->json();
    expect($resource['authorization_servers'])->toBe(['https://oauth.whisper.money']);
    expect($resource['resource'])->toEndWith('/mcp/oauth');

    // Fetched from that host, every auth-server endpoint stays on it (same origin as
    // the issuer), so the OAuth authorize link lives off the PWA origin and the
    // installed app can't capture it.
    $server = get('https://oauth.whisper.money/.well-known/oauth-authorization-server')
        ->assertOk()->json();
    expect($server['issuer'])->toBe('https://oauth.whisper.money');
    expect($server['authorization_endpoint'])->toStartWith('https://oauth.whisper.money/');
    expect($server['token_endpoint'])->toStartWith('https://oauth.whisper.money/');
    expect($server['registration_endpoint'])->toStartWith('https://oauth.whisper.money/');
});

/*
|--------------------------------------------------------------------------
| 401 bootstrap challenge (mandatory for Claude / ChatGPT)
|--------------------------------------------------------------------------
*/

it('returns a 401 bootstrap challenge for an unauthenticated OAuth MCP request', function () {
    $response = postJson('/mcp/oauth', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    $response->assertUnauthorized();

    $header = $response->headers->get('WWW-Authenticate');
    expect($header)->toContain('Bearer');
    expect($header)->toContain('resource_metadata=');
    expect($header)->toContain('/.well-known/oauth-protected-resource/mcp/oauth');
});

/*
|--------------------------------------------------------------------------
| Dynamic Client Registration (RFC 7591)
|--------------------------------------------------------------------------
*/

it('registers a public PKCE client via dynamic client registration', function () {
    $response = postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [CLAUDE_CALLBACK],
    ])->assertCreated()->assertJson([
        'token_endpoint_auth_method' => 'none',
        'scope' => 'mcp:use',
    ]);

    expect($response->json('client_id'))->not->toBeEmpty();
    expect($response->json('redirect_uris'))->toContain(CLAUDE_CALLBACK);
});

it('registers a native client with a loopback redirect on any port', function (string $callback) {
    // Claude Code (and other CLIs) listen on a random local port for the
    // OAuth callback, so a loopback URI is accepted whatever its port.
    postJson('/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => [$callback],
    ])->assertCreated();
})->with([
    'localhost' => 'http://localhost:53682/callback',
    '127.0.0.1' => 'http://127.0.0.1:61234/callback',
    '127.0.0.1, short path' => 'http://127.0.0.1:5555/cb',
    'localhost, other port' => 'http://localhost:1234/callback',
]);

it('registers the hosted connector callbacks', function (string $callback) {
    postJson('/oauth/register', [
        'client_name' => 'Connector',
        'redirect_uris' => [$callback],
    ])->assertCreated();
})->with([
    'Claude' => 'https://claude.ai/api/mcp/auth_callback',
    'ChatGPT' => 'https://chatgpt.com/connector_platform_oauth_redirect',
]);

it('rejects a DCR redirect URI outside the allowlist, plain-http or not', function (string $callback) {
    postJson('/oauth/register', [
        'client_name' => 'Evil',
        'redirect_uris' => [$callback],
    ])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
})->with([
    'plain-http host that is not loopback' => 'http://evil.example.com/callback',
    'https host outside the allowlist' => 'https://evil.example.com/callback',
    'loopback prefix hiding another host' => 'http://127.0.0.1:x@evil.com/cb',
    'localhost prefix hiding another host' => 'http://localhost:1234@evil.com/cb',
    'allowed host as user info' => 'https://claude.ai@evil.com/cb',
    'allowed host as a subdomain' => 'https://claude.ai.evil.com/cb',
    'allowed host with credentials' => 'https://user:pass@claude.ai/cb',
]);

it('rejects the whole registration when any redirect URI is outside the allowlist', function () {
    postJson('/oauth/register', [
        'client_name' => 'Evil',
        'redirect_uris' => [CLAUDE_CALLBACK, 'http://127.0.0.1:x@evil.com/cb'],
    ])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);

    expect(Passport::client()->newQuery()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| End-to-end Authorization Code + PKCE flow
|--------------------------------------------------------------------------
*/

it('authenticates a full Authorization Code + PKCE flow and serves read tools', function () {
    $user = User::factory()->create();
    $token = issueOAuthToken($user);

    expect($token)->not->toBeEmpty();

    withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/mcp/oauth', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'list_spaces', 'arguments' => (object) []],
        ])
        ->assertOk()
        ->assertSee('Personal', false);
});

/*
|--------------------------------------------------------------------------
| Write access over OAuth (Claude Desktop / ChatGPT connections can write)
|--------------------------------------------------------------------------
*/

it('lets an OAuth connection use write tools', function () {
    $user = User::factory()->create();
    $token = issueOAuthToken($user);

    withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/mcp/oauth', [
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => 'create_label',
                'arguments' => ['name' => 'Groceries', 'color' => 'blue'],
            ],
        ])
        ->assertOk()
        ->assertSee('Groceries', false);

    $label = Label::query()->where('user_id', $user->id)->first();
    expect($label)->not->toBeNull();
    expect($label->name)->toBe('Groceries');
});

/**
 * A client presenting an invalid/expired/wrong-signature bearer token to a
 * Passport-guarded endpoint (e.g. an MCP request or the /mcp/oauth flow) makes
 * league/oauth2-server throw an OAuthServerException. These are routine 4xx
 * OAuth protocol responses returned to the client, not server faults, so they
 * must not be reported to Sentry as errors (Sentry PHP-LARAVEL-4Q noise).
 */
test('does not report client-facing OAuthServerException (4xx)', function () {
    expect(app(ExceptionHandler::class)->shouldReport(OAuthServerException::accessDenied()))->toBeFalse();
    expect(app(ExceptionHandler::class)->shouldReport(OAuthServerException::invalidCredentials()))->toBeFalse();
});

/**
 * A genuine server_error (500) inside the OAuth flow IS a real fault and must
 * still be reported.
 */
test('still reports server_error OAuthServerException (5xx)', function () {
    expect(app(ExceptionHandler::class)->shouldReport(OAuthServerException::serverError('boom')))->toBeTrue();
});
