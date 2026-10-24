<?php

namespace App\Jobs\Concerns;

use App\Services\Cache\DataVersion;
use Closure;

/**
 * For jobs that write in bulk (a bank sync, an import, a backfill): every bump
 * the job causes is collected and flushed once when it finishes, instead of
 * one cache write per row it touches.
 */
trait DefersDataVersionBumps
{
    /**
     * @return list<Closure(object, Closure): mixed>
     */
    public function middleware(): array
    {
        return [
            fn (object $job, Closure $next): mixed => DataVersion::deferBumps(fn (): mixed => $next($job)),
        ];
    }
}
