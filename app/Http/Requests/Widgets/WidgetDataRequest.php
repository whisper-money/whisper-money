<?php

namespace App\Http\Requests\Widgets;

use App\Services\Widgets\Gateway\DashboardVariables;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What the page may send when it asks for widget data: the dashboard's
 * variables (`range[from]`, `range[to]`) and nothing else. The endpoints and
 * every other parameter come from the saved manifest; anything else in the
 * query is ignored.
 */
class WidgetDataRequest extends FormRequest
{
    /**
     * A dashboard spans a month, a quarter or a few years; more is a read
     * of the whole history on every widget.
     */
    private const int MaxRangeYears = 10;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['sometimes', 'array'],
            'range.from' => ['required_with:range', 'date_format:Y-m-d'],
            'range.to' => ['required_with:range', 'date_format:Y-m-d', 'after_or_equal:range.from'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || ! $this->has('range')) {
                return;
            }

            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->input('range.from'));

            if ($from->addYears(self::MaxRangeYears)->lt(CarbonImmutable::createFromFormat('!Y-m-d', $this->input('range.to')))) {
                $validator->errors()->add('range.to', __('The range can span at most :years years.', ['years' => self::MaxRangeYears]));
            }
        }];
    }

    /**
     * The range asked for, else the user's current month on their own clock.
     */
    public function variables(): DashboardVariables
    {
        if ($this->has('range')) {
            return new DashboardVariables(
                CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('range.from')),
                CarbonImmutable::createFromFormat('!Y-m-d', $this->validated('range.to')),
            );
        }

        return DashboardVariables::forMonthOf(CarbonImmutable::instance($this->user()->localToday()));
    }
}
