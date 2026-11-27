<?php

namespace App\Services\Forecast\Backtest;

use RuntimeException;

/**
 * Thrown when the forecast backtest, which only reads, is about to run a
 * statement that is not a read.
 */
class BacktestWriteRefused extends RuntimeException
{
    public static function for(string $statement): self
    {
        $verb = strtolower((string) strtok(ltrim($statement), " \n\t("));

        return new self("forecast:backtest only reads: a {$verb} statement was stopped before it ran.");
    }
}
