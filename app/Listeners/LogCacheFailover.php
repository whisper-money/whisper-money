<?php

namespace App\Listeners;

use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Support\Facades\Log;

class LogCacheFailover
{
    /**
     * Record a cache store that failed and was failed over.
     *
     * The failover store swallows the underlying exception so the request can
     * be served from the next store, which would otherwise make the failure
     * invisible — the deadlock this exists for used to arrive as a Sentry 500.
     * Warning matches the level the currency cache-write guard logs at: the
     * request survived, but a store that keeps failing is worth looking at.
     *
     * The event fires once per failing store per request, not once per cache
     * operation, so a broken store does not flood the log.
     */
    public function handle(CacheFailedOver $event): void
    {
        Log::warning('Cache store failed over', [
            'store' => $event->storeName,
            'error' => $event->exception->getMessage(),
        ]);
    }
}
