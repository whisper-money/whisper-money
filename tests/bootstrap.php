<?php

use Testcontainers\Modules\MySQLContainer;

/*
|--------------------------------------------------------------------------
| Test Bootstrap — Testcontainers
|--------------------------------------------------------------------------
|
| This bootstrap starts an ephemeral MySQL container before any tests run.
| Each test process gets its own isolated database on a random port, so
| multiple agents, worktrees, or CI jobs can run tests in parallel
| without conflicts.
|
| Set TESTCONTAINERS=false to skip container creation and use the
| database configured in your .env file instead.
|
*/

$useContainers = filter_var(
    $_SERVER['TESTCONTAINERS'] ?? $_ENV['TESTCONTAINERS'] ?? 'true',
    FILTER_VALIDATE_BOOLEAN,
);

// Autoload must be available before anything else
require __DIR__.'/../vendor/autoload.php';

// Create a minimal .env file when none exists so PHPDotenv does not
// emit a file_get_contents warning on every single test.
$envPath = __DIR__.'/../.env';
if (! file_exists($envPath)) {
    file_put_contents($envPath, "APP_ENV=testing\n");
}

if ($useContainers) {
    $container = (new MySQLContainer('8.0'))
        ->withMySQLDatabase('testing')
        ->withMySQLUser('testing', 'testing')
        ->start();

    // Stop and remove the container when the PHP process exits.
    // We wrap in try/catch because an uncaught exception inside a
    // shutdown function becomes a fatal error in PHP, which would leave
    // the container running.
    $cleanup = function () use ($container): void {
        try {
            $container->stop();
        } catch (Throwable) {
            // Silently ignore — best-effort cleanup.
        }
    };

    register_shutdown_function($cleanup);

    // register_shutdown_function is NOT called for signals such as SIGINT
    // (Ctrl+C) or SIGTERM. Install async signal handlers so the container
    // is also removed when the test run is interrupted.
    if (extension_loaded('pcntl')) {
        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, function () use ($cleanup, $signal): void {
                $cleanup();
                exit(128 + $signal);
            });
        }
    }

    $host = $container->getHost();
    $port = (string) $container->getFirstMappedPort();

    // Set environment variables before Laravel boots so config/database.php
    // reads the dynamic host and port via env(). Both putenv() and $_ENV/$_SERVER
    // are needed because Laravel's env() helper checks multiple sources.
    $env = [
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_DATABASE' => 'testing',
        'DB_USERNAME' => 'testing',
        'DB_PASSWORD' => 'testing',
    ];

    // Generate an APP_KEY if one is not already set, so tests can run
    // without a .env file (e.g. fresh worktrees, CI environments).
    if (empty($_SERVER['APP_KEY'] ?? $_ENV['APP_KEY'] ?? getenv('APP_KEY'))) {
        $env['APP_KEY'] = 'base64:'.base64_encode(random_bytes(32));
    }

    foreach ($env as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
