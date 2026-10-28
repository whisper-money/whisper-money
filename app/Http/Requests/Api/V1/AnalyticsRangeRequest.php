<?php

namespace App\Http\Requests\Api\V1;

use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The date range every analytics endpoint reports on. The previous period the
 * figures are compared with is the same number of days right before it, or the
 * same number of whole months when the range is whole months.
 */
class AnalyticsRangeRequest extends FormRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = PeriodComparator::rules();

        return [
            /**
             * The first day of the range, inclusive.
             *
             * @format date
             *
             * @example 2026-10-01
             */
            'from' => $rules['from'],
            /**
             * The last day of the range, inclusive. Not before `from`.
             *
             * @format date
             *
             * @example 2026-10-31
             */
            'to' => [...$rules['to'], 'after_or_equal:from'],
        ];
    }

    public function period(): PeriodComparator
    {
        return new PeriodComparator(Carbon::parse($this->validated('from')), Carbon::parse($this->validated('to')));
    }
}
