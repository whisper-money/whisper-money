<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

it('issues tokens prefixed by the surface their abilities belong to, with a checksum and without the id', function (array $abilities, string $prefix) {
    $token = User::factory()->create()->createToken('X', $abilities);

    expect($token->plainTextToken)
        ->toMatch('/^'.$prefix.'[A-Za-z0-9]{40}[0-9a-f]{8}$/')
        ->not->toContain('|');
    expect($token->accessToken->token)->toBe(hash('sha256', $token->plainTextToken));
})->with([
    'api read' => [['api:read'], 'wm_api_'],
    'api read & write' => [['api:read', 'api:write'], 'wm_api_'],
    'mcp read' => [['mcp:read'], 'wm_mcp_'],
    'mcp read & write' => [['mcp:read', 'mcp:write'], 'wm_mcp_'],
    'other ability' => [['other'], 'wm_'],
    'wildcard' => [['*'], 'wm_'],
]);

it('refuses a token that carries both API and MCP abilities', function () {
    User::factory()->create()->createToken('X', ['api:read', 'mcp:read']);
})->throws(InvalidArgumentException::class);

it('resolves a prefixed token by hashing the whole string', function () {
    $token = User::factory()->create()->createToken('X', ['api:read']);

    expect(PersonalAccessToken::findToken($token->plainTextToken)?->is($token->accessToken))->toBeTrue();
    expect(PersonalAccessToken::findToken($token->plainTextToken.'x'))->toBeNull();
});

it('still resolves a legacy id|secret token', function () {
    $secret = Str::random(40);
    $legacy = User::factory()->create()->tokens()->create([
        'name' => 'Legacy',
        'token' => hash('sha256', $secret),
        'abilities' => ['mcp:read'],
    ]);

    expect(PersonalAccessToken::findToken("{$legacy->id}|{$secret}")?->is($legacy))->toBeTrue();
});
