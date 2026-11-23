<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * On SIGTERM supervisord stops its programs one priority at a time, highest
 * first. The web stack has to go last, or Traefik keeps sending requests to a
 * container whose php-fpm is already gone and every deploy serves 502s.
 *
 * @return Collection<string, array<string, string>>
 */
function supervisorPrograms(): Collection
{
    $programs = [];
    $current = null;

    foreach (explode("\n", File::get(base_path('docker/supervisor/supervisord.conf'))) as $line) {
        $line = trim($line);

        if (preg_match('/^\[program:(.+)\]$/', $line, $matches) === 1) {
            $current = $matches[1];
            $programs[$current] = [];
        } elseif (Str::startsWith($line, '[')) {
            $current = null;
        } elseif ($current !== null && Str::contains($line, '=') && ! Str::startsWith($line, ';')) {
            [$key, $value] = explode('=', $line, 2);
            $programs[$current][trim($key)] = trim($value);
        }
    }

    return collect($programs);
}

function supervisorPriority(array $program): int
{
    return (int) ($program['priority'] ?? 999);
}

test('nginx and php-fpm stop gracefully', function () {
    $programs = supervisorPrograms();

    expect($programs['nginx']['stopsignal'] ?? null)->toBe('QUIT')
        ->and($programs['php-fpm']['stopsignal'] ?? null)->toBe('QUIT');
});

test('the web stack stops after every other program', function () {
    $webStack = ['nginx', 'php-fpm', 'redis', 'memcached'];
    $programs = supervisorPrograms();

    $lowestOtherPriority = $programs->except($webStack)->map(supervisorPriority(...))->min();

    expect($programs->except($webStack))->not->toBeEmpty();

    foreach ($webStack as $name) {
        expect(supervisorPriority($programs[$name]))->toBeLessThan($lowestOtherPriority, "{$name} must stop after the workers");
    }
});

test('nginx stops before php-fpm, and php-fpm before the stores it reads', function () {
    $programs = supervisorPrograms();

    expect(supervisorPriority($programs['nginx']))->toBeGreaterThan(supervisorPriority($programs['php-fpm']))
        ->and(supervisorPriority($programs['php-fpm']))->toBeGreaterThan(supervisorPriority($programs['redis']))
        ->and(supervisorPriority($programs['php-fpm']))->toBeGreaterThan(supervisorPriority($programs['memcached']));
});
