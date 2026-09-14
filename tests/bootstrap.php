<?php

use Testcontainers\Container\GenericContainer;
use Testcontainers\Modules\MySQLContainer;
use Tests\Support\Ryuk;

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
    // The session label must be unique per process: `pest --parallel` starts one
    // container per worker, and a reaper shared between them would take a live
    // sibling's database down as soon as the first worker finished.
    $session = getmypid().'-'.bin2hex(random_bytes(4));

    // Ryuk removes every container labelled with our session as soon as the
    // connection we hold against it drops. It is started first and labels are
    // applied by Docker on create, so the database is covered from the moment it
    // exists — including the window inside start() where nothing else holds a
    // handle to it yet.
    // Pull through the CLI: the library's own pull path asks the Docker credential
    // helper for Hub credentials and throws when the machine never ran `docker
    // login`, which would break the very first run on a fresh checkout.
    $ryukImage = 'testcontainers/ryuk:0.11.0';
    exec("docker image inspect {$ryukImage} >/dev/null 2>&1 || docker pull {$ryukImage} >/dev/null 2>&1");

    $ryuk = (new GenericContainer($ryukImage))
        ->withMount('/var/run/docker.sock', '/var/run/docker.sock')
        ->withExposedPorts(8080)
        ->withAutoRemove(true)
        ->start();

    Ryuk::watch($ryuk->getHost(), (int) $ryuk->getFirstMappedPort(), $session);

    $container = (new MySQLContainer('8.0'))
        ->withMySQLDatabase('testing')
        ->withMySQLUser('testing', 'testing')
        ->withLabels([Ryuk::LABEL => $session])
        ->withAutoRemove(true)
        ->start();

    // Stop and remove the containers when the PHP process exits. Ryuk would get to
    // them on its own, but only after its reconnection grace period, and there is
    // no reason to keep the memory busy that long on the happy path.
    // We wrap in try/catch because an uncaught exception inside a
    // shutdown function becomes a fatal error in PHP, which would leave
    // the container running.
    // The database goes first, so a failure stopping it still leaves the reaper
    // behind to deal with it. They are stopped independently because AutoRemove
    // deletes a container on stop, which makes the library's own delete throw.
    $cleanup = function () use ($container, $ryuk): void {
        foreach ([$container, $ryuk] as $started) {
            try {
                $started->stop();
            } catch (Throwable) {
                // Silently ignore — Ryuk reaps whatever is left once our connection drops.
            }
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
