<?php

namespace App\Exceptions\Banking;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

/**
 * The banking provider rejected the requested transactions date range as wider
 * than the bank is willing to serve (EnableBanking HTTP 422 "Wrong transactions
 * period requested"). Recoverable by retrying with a narrower window, so it is
 * not reported: the sync layer clamps and retries, and only skips the account
 * if even the narrowest window is refused.
 */
class WrongTransactionsPeriodException extends Exception implements ShouldntReport
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
