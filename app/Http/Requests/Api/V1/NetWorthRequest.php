<?php

namespace App\Http\Requests\Api\V1;

use App\Services\PeriodComparator;

class NetWorthRequest extends AnalyticsRangeRequest
{
    /**
     * The most days a daily evolution may cover: a point per day per account.
     */
    private const MAX_DAILY_DAYS = 366;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /**
             * `monthly` (the default) for a point at the end of each month of the range, `daily` for one per day.
             *
             * @example monthly
             */
            'granularity' => ['sometimes', 'in:monthly,daily'],
        ];
    }

    public function daily(): bool
    {
        return $this->input('granularity') === 'daily';
    }

    protected function rangeRefusal(PeriodComparator $period): ?string
    {
        return $this->daily() && $period->days() > self::MAX_DAILY_DAYS
            ? 'A daily net worth can cover at most '.self::MAX_DAILY_DAYS.' days. Ask for monthly granularity for a longer range.'
            : parent::rangeRefusal($period);
    }
}
