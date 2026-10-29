<?php

use App\Support\VerifiedOAuthApps;

function verifiedOAuthApps(): VerifiedOAuthApps
{
    return new VerifiedOAuthApps([
        'Claude' => ['claude.ai'],
        'ChatGPT' => ['chatgpt.com'],
    ]);
}

it('verifies a client whose every redirect URI reaches one app\'s hosts', function (array $redirectUris, string $app) {
    expect(verifiedOAuthApps()->nameFor($redirectUris))->toBe($app);
})->with([
    'Claude callback' => [['https://claude.ai/api/mcp/auth_callback'], 'Claude'],
    'ChatGPT callback' => [['https://chatgpt.com/connector_platform_oauth_redirect'], 'ChatGPT'],
    'several Claude callbacks' => [['https://claude.ai/api/mcp/auth_callback', 'https://claude.ai/other'], 'Claude'],
    'explicit default port' => [['https://claude.ai:443/api/mcp/auth_callback'], 'Claude'],
    'uppercase scheme and host' => [['HTTPS://CLAUDE.AI/api/mcp/auth_callback'], 'Claude'],
]);

it('does not verify a client that could send the code anywhere else', function (array $redirectUris) {
    expect(verifiedOAuthApps()->nameFor($redirectUris))->toBeNull();
})->with([
    'no redirect URIs' => [[]],
    'one Claude and one localhost callback' => [['https://claude.ai/api/mcp/auth_callback', 'http://localhost:8080/callback']],
    'Claude and ChatGPT callbacks mixed' => [['https://claude.ai/api/mcp/auth_callback', 'https://chatgpt.com/callback']],
    'a host ending in claude.ai' => [['https://claude.ai.evil.com/callback']],
    'a subdomain of claude.ai' => [['https://evil.claude.ai/callback']],
    'claude.ai in the path' => [['https://evil.com/claude.ai']],
    'plain http' => [['http://claude.ai/api/mcp/auth_callback']],
    'another port' => [['https://claude.ai:8443/api/mcp/auth_callback']],
    'a user in the URI' => [['https://x@claude.ai/api/mcp/auth_callback']],
    'a user and password in the URI' => [['https://x:y@claude.ai/api/mcp/auth_callback']],
    'a custom scheme' => [['claude://claude.ai/callback']],
    'loopback' => [['http://localhost:3000/callback']],
    'not a URI' => [['claude.ai']],
]);
