<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * A backend that sends one request per transaction lost some of them to a
 * transient provider failure (rate limit, overload, unreachable). It carries
 * the results that did succeed so the chunk is not discarded with it.
 */
class TransientCategorizationException extends RuntimeException
{
    /**
     * @param  list<array<string, mixed>>  $results
     */
    public function __construct(string $message, public readonly array $results = [])
    {
        parent::__construct($message);
    }
}
