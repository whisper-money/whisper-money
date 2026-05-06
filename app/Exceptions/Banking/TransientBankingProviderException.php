<?php

namespace App\Exceptions\Banking;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Throwable;

class TransientBankingProviderException extends Exception implements ShouldntReport
{
    public function __construct(
        string $message,
        public readonly ?string $provider = null,
        public readonly ?int $statusCode = null,
        public readonly ?string $providerCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
