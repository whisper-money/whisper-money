<?php

use App\Models\Label;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

use function Pest\Laravel\get;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/**
 * A hosted-callback redirect URI inside the config('mcp.redirect_domains')
 * allowlist (Anthropic's Claude connector callback).
 */
const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

/**
 * Generate a PKCE (verifier, S256 challenge) pair.
 *
 * @return array{0: string, 1: string}
 */
function pkcePair(): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [$verifier, $challenge];
}

/**
 * Drive the full Authorization Code + PKCE flow for the user and return the
 * issued bearer access token, exactly as Claude/ChatGPT would obtain one:
 * register a public client, authorize with a code challenge, approve on the
 * consent screen, then exchange the code + verifier at the token endpoint.
 *
 * @param  list<string>  $scopes
 */
function issueOAuthToken(User $user, array $scopes = ['mcp:use']): string
{
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Claude',
        redirectUris: [CLAUDE_CALLBACK],
        confidential: false,
    );

    [$verifier, $challenge] = pkcePair();

    // 1. Authorize: renders the consent screen and primes the session.
    $authorize = test()->actingAs($user)->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->id,
        'redirect_uri' => CLAUDE_CALLBACK,
        'response_type' => 'code',
        'scope' => implode(' ', $scopes),
        'state' => 'state-token',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
    ]))->assertOk();

    // The consent form carries the single-use auth token we must echo back.
    preg_match('/name="auth_token" value="([^"]+)"/', $authorize->getContent(), $matches);
    $authToken = $matches[1] ?? null;
    expect($authToken)->not->toBeNull();

    // 2. Approve: redirects back to the client with the authorization code.
    $approve = test()->actingAs($user)->post('/oauth/authorize', [
        'auth_token' => $authToken,
        'client_id' => $client->id,
        'state' => 'state-token',
    ])->assertRedirect();

    parse_str((string) parse_url($approve->headers->get('Location'), PHP_URL_QUERY), $redirect);
    expect($redirect['code'] ?? null)->not->toBeNull();

    // 3. Exchange the code + verifier for a token (public client, no secret).
    $token = test()->post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->id,
        'redirect_uri' => CLAUDE_CALLBACK,
        'code_verifier' => $verifier,
        'code' => $redirect['code'],
    ])->assertOk();

    return $token->json('access_token');
}

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

it('serves authorization server metadata advertising PKCE and the mcp:use scope', function () {
    $json = get('/.well-known/oauth-authorization-server')->assertOk()->json();

    expect($json['response_types_supported'])->toBe(['code']);
    expect($json['code_challenge_methods_supported'])->toBe(['S256']);
    expect($json['scopes_supported'])->toBe(['mcp:use']);
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
    ])->assertOk()->assertJson([
        'token_endpoint_auth_method' => 'none',
        'scope' => 'mcp:use',
    ]);

    expect($response->json('client_id'))->not->toBeEmpty();
    expect($response->json('redirect_uris'))->toContain(CLAUDE_CALLBACK);
});

it('rejects a DCR redirect URI outside the allowlist', function () {
    postJson('/oauth/register', [
        'client_name' => 'Evil',
        'redirect_uris' => ['https://evil.example.com/callback'],
    ])->assertStatus(400)->assertJson(['error' => 'invalid_redirect_uri']);
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
