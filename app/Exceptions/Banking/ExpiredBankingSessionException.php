<?php

namespace App\Exceptions\Banking;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

/**
 * The provider will serve nothing more on this connection until the user
 * authorizes it again. A lapsed consent window is the common cause but not the
 * only one — a session revoked at the bank, or superseded by a newer
 * authorization, lands here too.
 */
class ExpiredBankingSessionException extends Exception implements ShouldntReport
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
