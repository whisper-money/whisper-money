<?php

namespace App\Http\Requests\Api\V1;

use App\Services\CashflowAnalyticsService;

class CashflowRequest extends AnalyticsRangeRequest
{
    /**
     * The trend has one row per month the range touches, and no more than
     * these: a longer range is refused rather than its trend cut short.
     */
    protected const MAX_MONTHS = CashflowAnalyticsService::MAX_TREND_MONTHS;
}
