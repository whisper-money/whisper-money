<?php

namespace App\Http\Requests\Api\V1;

use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The date range every analytics endpoint reports on. The previous period the
 * figures are compared with is the same number of days right before it, or the
 * same number of whole months when the range is whole months.
 */
class AnalyticsRangeRequest extends FormRequest
{
    /**
     * The most calendar months a range may touch: every one of them is walked
     * through, so an unbounded range would be an unbounded amount of work.
     */
    protected const MAX_MONTHS = 120;

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
            'from' => [...$rules['from'], 'date_format:Y-m-d'],
            /**
             * The last day of the range, inclusive. Not before `from`.
             *
             * @format date
             *
             * @example 2026-10-31
             */
            'to' => [...$rules['to'], 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    /**
     * Refuse a range longer than the endpoint reports on, under `to`.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && ($refusal = $this->rangeRefusal($this->period())) !== null) {
                $validator->errors()->add('to', $refusal);
            }
        }];
    }

    public function period(): PeriodComparator
    {
        return new PeriodComparator(Carbon::parse($this->input('from')), Carbon::parse($this->input('to')));
    }

    /**
     * Why the range is too long, or null when it is not.
     */
    protected function rangeRefusal(PeriodComparator $period): ?string
    {
        $months = ($period->to->year - $period->from->year) * 12 + $period->to->month - $period->from->month + 1;

        return $months > static::MAX_MONTHS
            ? 'The range can touch at most '.static::MAX_MONTHS.' calendar months.'
            : null;
    }
}
