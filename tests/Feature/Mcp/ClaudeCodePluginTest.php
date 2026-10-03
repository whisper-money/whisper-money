<?php

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * The Claude Code plugin is installed straight from this repository
 * (`/plugin marketplace add whisper-money/whisper-money`), so a broken path or
 * a server URL that drifts from the routes ships to every user who installs it.
 */
function readRepoJson(string $path): array
{
    return json_decode((string) file_get_contents(base_path($path)), true, flags: JSON_THROW_ON_ERROR);
}

it('lists a plugin whose manifest carries the same name', function () {
    $marketplace = readRepoJson('.claude-plugin/marketplace.json');

    expect($marketplace['plugins'])->toHaveCount(1);

    $entry = $marketplace['plugins'][0];
    $manifest = readRepoJson(ltrim($entry['source'], './').'/.claude-plugin/plugin.json');

    expect($manifest['name'])->toBe($entry['name']);
});

it('points the plugin at the OAuth MCP endpoint this app serves', function () {
    $server = readRepoJson('plugins/whisper-money/.mcp.json')['mcpServers']['whisper-money'];

    expect($server['type'])->toBe('http')
        ->and(parse_url($server['url'], PHP_URL_SCHEME))->toBe('https')
        ->and(parse_url($server['url'], PHP_URL_PATH))->toBe('/mcp/oauth');

    $served = collect(RouteFacade::getRoutes()->getRoutes())
        ->contains(fn (Route $route): bool => $route->uri() === 'mcp/oauth' && in_array('POST', $route->methods(), true));

    expect($served)->toBeTrue();
});

it('bundles both skills inside the plugin directory', function () {
    // Claude Code only copies the plugin directory on install, so the skills
    // must live inside it rather than be referenced from the repo root.
    foreach (['whisper-money', 'whisper-money-docs'] as $skill) {
        $path = base_path("plugins/whisper-money/skills/{$skill}/SKILL.md");

        expect(is_file($path))->toBeTrue("missing {$skill}")
            ->and(file_get_contents($path))->toContain("name: {$skill}");
    }
});
