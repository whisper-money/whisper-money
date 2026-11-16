<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/**
 * The nonce of the script policy a response carries, from $header.
 */
function cspNonceOf(TestResponse $response, string $header = 'Content-Security-Policy'): string
{
    preg_match("/script-src 'nonce-([^']+)'/", (string) $response->headers->get($header), $matches);

    expect($matches)->toHaveKey(1);

    return $matches[1];
}

/**
 * The opening tags of every script the page would run: data blocks
 * (`type="application/json"`, Inertia's page data) never run.
 *
 * @return list<string>
 */
function executableScriptTags(TestResponse $response): array
{
    preg_match_all('/<script\b[^>]*>/i', (string) $response->getContent(), $matches);

    return array_values(array_filter($matches[0], fn (string $tag): bool => ! str_contains($tag, 'application/json')));
}

it('enforces a per-request nonce that every inline script of the page carries', function () {
    $first = get(route('login'))->assertOk();
    $second = get(route('login'))->assertOk();
    $nonce = cspNonceOf($first);
    $policy = (string) $first->headers->get('Content-Security-Policy');

    expect($policy)
        ->toContain("script-src 'nonce-{$nonce}' 'strict-dynamic' 'self'", "object-src 'none'", "base-uri 'self'", "worker-src 'self' blob:", "frame-ancestors 'none'")
        ->not->toContain('unsafe-inline', 'unsafe-eval', 'data:', 'style-src')
        ->and($first->headers->all('content-security-policy'))->toHaveCount(1)
        ->and(cspNonceOf($second))->not->toBe($nonce)
        ->and(executableScriptTags($first))->not->toBeEmpty()
        ->each->toContain("nonce=\"{$nonce}\"");
});

it('only reports the script policy in report mode, still enforcing frame-ancestors', function () {
    config(['security.csp.script_src' => 'report']);

    $response = get(route('login'))->assertOk();
    $nonce = cspNonceOf($response, 'Content-Security-Policy-Report-Only');

    expect($response->headers->get('Content-Security-Policy'))->toBe("frame-ancestors 'none'")
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("'strict-dynamic'", "object-src 'none'")
        ->and(executableScriptTags($response))->each->toContain("nonce=\"{$nonce}\"");
});

it('sends no script policy and no nonce when off', function () {
    config(['security.csp.script_src' => 'off']);

    $response = get(route('login'))->assertOk();

    expect($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
        ->and((string) $response->getContent())->not->toContain('nonce=');
});

it('keeps the framing guarantee in the same header on the pages that deny framing', function () {
    $response = actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY');

    expect($response->headers->all('content-security-policy'))->toHaveCount(1)
        ->and($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'", "'nonce-".cspNonceOf($response)."'");

    config(['security.csp.script_src' => 'off']);

    actingAs(User::factory()->create())
        ->get(route('api-tokens.index'))
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");
});

it('leaves JSON answers alone: Inertia visits, the API and MCP', function () {
    // With built assets a visit that names another asset version is answered
    // 409, and the version is only known once a page has been rendered.
    $version = get(route('login'))->viewData('page')['version'];
    $visit = get(route('login'), [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => $version,
    ]);
    $api = getJson('/api/v1/accounts');
    $mcp = $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);

    foreach ([$visit, $api, $mcp] as $response) {
        expect($response->headers->get('Content-Type'))->not->toStartWith('text/html')
            ->and($response->headers->has('Content-Security-Policy-Report-Only'))->toBeFalse()
            ->and((string) $response->headers->get('Content-Security-Policy'))->not->toContain('script-src');
    }
});

it('spares the debug error page, whose inline scripts carry no nonce, and nothing else', function (bool $debug, bool $hasPolicy) {
    Route::middleware('web')->get('/__csp-test-error', fn () => throw new RuntimeException('Boom.'));
    config(['app.debug' => $debug]);

    $response = get('/__csp-test-error')->assertStatus(500);

    expect(str_contains((string) $response->headers->get('Content-Security-Policy'), 'script-src'))->toBe($hasPolicy);
})->with([
    'debug' => [true, false],
    'production' => [false, true],
]);
