<?php

namespace App\Http\Requests\Forecast;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Http\Requests\Forecast\Concerns\ScopesForecastEdits;
use App\Services\Forecast\ForecastCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The user's own figure for the estimated rest of a parent category on one
 * account (no category: the spend no category covers), for one month or
 * from it on. The amount is what they expect to spend, positive.
 */
class StoreForecastEstimateRequest extends FormRequest
{
    use ScopesForecastEdits, ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_id' => ['required', 'string', $this->includedAccount()],
            'category_id' => ['nullable', 'string', $this->userOwned('categories')->whereNull('parent_id')],
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'scope' => ['required', Rule::in(['month', 'from_here'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && ! $this->editWindow()->allowsMonth($this->user(), $this->month())) {
                    $validator->errors()->add('month', __('This month can no longer be changed.'));
                }
            },
        ];
    }

    public function month(): string
    {
        return (string) $this->input('month');
    }

    public function startsOn(): string
    {
        return $this->month().'-01';
    }

    public function endsOn(): ?string
    {
        return $this->input('scope') === 'month' ? ForecastCalendar::horizonEnd($this->startsOn(), 1) : null;
    }
}
