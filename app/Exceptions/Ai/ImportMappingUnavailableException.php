<?php

namespace App\Exceptions\Ai;

use RuntimeException;

/**
 * Jev gave no usable column-mapping suggestion: it kept failing transiently
 * until the attempts ran out, or it answered without a single readable field.
 * Reported (at most once every few minutes) so an outage is seen; the user
 * just keeps the header guess.
 */
class ImportMappingUnavailableException extends RuntimeException
{
    public static function exhausted(int $attempts): self
    {
        return new self("Jev failed transiently on all {$attempts} attempts at an import column suggestion.");
    }

    public static function malformed(int $status): self
    {
        return new self("Jev answered an import column suggestion ({$status}) without a single readable field.");
    }
}
