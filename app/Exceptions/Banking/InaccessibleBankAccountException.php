<?php

namespace App\Exceptions\Banking;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

class InaccessibleBankAccountException extends Exception implements ShouldntReport
{
    public function __construct(
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
