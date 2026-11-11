<?php

namespace App\Http\Requests\Widgets;

use App\Services\Widgets\Gateway\DashboardVariables;
use Carbon\CarbonImmutable;
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

        $today = CarbonImmutable::instance($this->user()->localToday());

        return new DashboardVariables($today->startOfMonth(), $today->endOfMonth()->startOfDay());
    }
}
