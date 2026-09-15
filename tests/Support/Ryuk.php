<?php

namespace Tests\Support;

use RuntimeException;

/**
 * Connection to Ryuk, the testcontainers reaper.
 *
 * Ryuk watches the connection we open against it and, as soon as that connection
 * drops, removes every container carrying the session label we registered. That is
 * the only cleanup that survives the process being killed outright, dying on a PHP
 * fatal, or having its terminal taken away — none of which run shutdown functions
 * or signal handlers.
 */
class Ryuk
{
    public const LABEL = 'money.whisper.test-session';

    /**
     * The connection Ryuk watches.
     *
     * PHP closes a stream as soon as the last reference to it goes out of scope, and
     * Ryuk reads that as "the run is over" and reaps. So the socket is parked here
     * for the lifetime of the process and never closed on purpose.
     *
     * @var resource|null
     */
    private static $connection = null;

    /**
     * The filter Ryuk reaps by: a URL-encoded query string, one per line.
     */
    public static function filter(string $session): string
    {
        return 'label='.urlencode(self::LABEL.'='.$session);
    }

    /**
     * Register the session filter and hold the connection open.
     */
    public static function watch(string $host, int $port, string $session): void
    {
        $socket = false;
        $error = '';

        // This doubles as the wait for the port to open. The library ships a
        // WaitForHostPort strategy, but it connects and disconnects to probe, and a
        // dropped connection is precisely how Ryuk is told the run is over: it shuts
        // itself down a few seconds later. Connecting once and holding on avoids
        // racing that countdown.
        for ($attempt = 0; $attempt < 20 && $socket === false; $attempt++) {
            $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $error, timeout: 2);

            if ($socket === false) {
                usleep(250_000);
            }
        }

        if ($socket === false) {
            throw new RuntimeException("Could not connect to the Ryuk reaper: {$error}");
        }

        // Ryuk answers in milliseconds. Without this, a reaper that accepts the
        // connection and then says nothing would stall the run for
        // default_socket_timeout — a minute of no output before failing.
        stream_set_timeout($socket, 5);

        fwrite($socket, self::filter($session)."\n");

        if (! str_contains((string) fgets($socket), 'ACK')) {
            throw new RuntimeException('Ryuk did not acknowledge the session filter.');
        }

        self::$connection = $socket;
    }
}
