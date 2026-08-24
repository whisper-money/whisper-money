<?php

namespace App\Exceptions\Banking;

/**
 * A banking failure that knows which provider endpoint produced it.
 *
 * The two exceptions carrying it already expose the property, so this only
 * gives the logs one type to ask instead of a cascade repeated per call site.
 */
interface CarriesBankingOperation
{
    public ?string $operation { get; }
}
