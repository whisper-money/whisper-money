<?php

namespace App\Console\Commands;

use App\Models\ApiRequest;
use Illuminate\Support\Carbon;

class ApiUsageStatsCommand extends UsageStatsCommand
{
    protected $signature = 'stats:api-usage {--days=30 : Days of history to report on} {--top=20 : Users listed in the per-user table}';

    protected $description = 'Show public API usage: requests per endpoint, status, credential, user and day';

    protected function surface(): string
    {
        return 'API';
    }

    protected function unit(): string
    {
        return 'request';
    }

    protected function dimension(): string
    {
        return 'endpoint';
    }

    protected function model(): string
    {
        return ApiRequest::class;
    }

    /**
     * Which answers callers get, and which credentials (a personal token, or
     * each OAuth client) they come through: a burst of requests from one new
     * OAuth client is the first sign of a misleading app.
     */
    protected function renderExtra(Carbon $since, int $total): void
    {
        $this->renderShares('By status', 'Status', ['status'], $since, $total);
        $this->renderShares('By credential', 'Credential', ['credential', 'oauth_client_id'], $since, $total);
    }
}
