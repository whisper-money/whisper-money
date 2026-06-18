<?php

namespace App\Exceptions\Banking;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

class ExpiredBankingSessionException extends Exception implements ShouldntReport
{
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
