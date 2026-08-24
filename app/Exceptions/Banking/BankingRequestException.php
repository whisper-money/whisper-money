<?php

namespace App\Exceptions\Banking;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * A provider request that matched none of the classified failures, re-raised
 * carrying the operation it came from.
 *
 * It extends RequestException on purpose. A 429 matches no branch of the
 * provider's error ladder and the job keys its rate-limit handling on
 * `instanceof RequestException`, so subclassing leaves that path byte for byte
 * as it was while giving the logs the one thing they were missing: whether it
 * was the balances or the transactions endpoint that hit the limit.
 */
class BankingRequestException extends RequestException implements CarriesBankingOperation
{
    public function __construct(Response $response, public readonly string $operation)
    {
        parent::__construct($response);
    }
}
