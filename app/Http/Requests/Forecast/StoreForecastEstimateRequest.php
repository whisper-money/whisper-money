<?php

namespace App\Http\Requests\Forecast;

use App\Enums\ForecastEstimateKind;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Http\Requests\Forecast\Concerns\ScopesForecastEdits;
use App\Models\Category;
use App\Services\Forecast\ForecastCalendar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The user's own figure for a parent category on one account, for one month
 * or from it on, on its side: income or spend. Without a category it is
 * "Other income" or "Other spending", told apart by `kind`; with one, the
 * side is the category's (a `kind` sent along must agree with it). The
 * amount is what they expect to receive or spend, positive.
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
            'kind' => ['required_without:category_id', 'nullable', Rule::enum(ForecastEstimateKind::class)],
            'month' => ['required', 'date_format:Y-m'],
            'amount' => ['required', 'integer', 'min:0', 'max:'.self::MAX_AMOUNT],
            'scope' => ['required', Rule::in(['month', 'from_here'])],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->filled('kind') && $this->kind() !== ForecastEstimateKind::from((string) $this->input('kind'))) {
                    $validator->errors()->add('kind', __('This category is on the other side of the forecast.'));
                }

                if (! $this->editWindow()->allowsMonth($this->user(), $this->month())) {
                    $validator->errors()->add('month', __('This month can no longer be changed.'));
                }
            },
        ];
    }

    /**
     * The side the adjustment moves: its category's, which is where the
     * forecast puts that money, else the one sent.
     */
    public function kind(): ForecastEstimateKind
    {
        $categoryId = $this->input('category_id');

        if ($categoryId === null) {
            return ForecastEstimateKind::from((string) $this->input('kind'));
        }

        return ForecastEstimateKind::forCategoryType(Category::query()->whereKey($categoryId)->value('type'));
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
