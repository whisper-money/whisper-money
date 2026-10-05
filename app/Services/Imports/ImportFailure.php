<?php

namespace App\Services\Imports;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Throwable;

/**
 * What a full import keeps about an exception: enough to find the cause,
 * nothing from the user's data. A database error's message spells out the
 * SQL with its bound values (descriptions, notes, amounts), so of a
 * `QueryException` only the SQLSTATE and the driver's own error code are
 * kept; any other message is cut before Laravel appends the SQL or the
 * connection, and shortened. Used for the log lines, Sentry Logs included,
 * and for what the import row stores.
 */
final class ImportFailure
{
    /** Long enough for a real message, short enough to hold no data dump. */
    private const MESSAGE_LENGTH = 200;

    /**
     * @return array{exception: string, message: string}
     */
    public static function context(Throwable $exception): array
    {
        return ['exception' => $exception::class, 'message' => self::message($exception)];
    }

    /** "Class: message", for the import row. */
    public static function describe(Throwable $exception): string
    {
        return $exception::class.': '.self::message($exception);
    }

    private static function message(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $driverCode = $exception->errorInfo[1] ?? null;

            return 'SQLSTATE['.($exception->errorInfo[0] ?? $exception->getCode()).']'.($driverCode !== null ? " ({$driverCode})" : '');
        }

        $message = (string) preg_replace('/\s*\((SQL|Connection):.*$/s', '', $exception->getMessage());

        return Str::limit(trim($message), self::MESSAGE_LENGTH, '…');
    }
}
