<?php

namespace App\Http\Requests\Api\V1;

class NetWorthRequest extends AnalyticsRangeRequest
{
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
        return $this->validated('granularity') === 'daily';
    }
}
