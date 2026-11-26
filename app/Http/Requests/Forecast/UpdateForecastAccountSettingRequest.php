<?php

namespace App\Http\Requests\Forecast;

use App\Http\Requests\Forecast\Concerns\ScopesForecastEdits;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;

/**
 * How one account takes part in the forecast: the balance its owner wants
 * to keep (minor units; null for none), the yearly growth of an investment
 * or property as a percentage, and whether the forecast covers it at all.
 */
class UpdateForecastAccountSettingRequest extends FormRequest
{
    use ScopesForecastEdits;

    public function authorize(): bool
    {
        $this->abortUnlessEditableAccount($this->account());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'minimum_balance' => ['sometimes', 'nullable', 'integer', 'between:-'.self::MAX_AMOUNT.','.self::MAX_AMOUNT],
            'annual_rate' => ['sometimes', 'nullable', 'numeric', 'between:-100,100'],
            'included' => ['sometimes', 'boolean'],
        ];
    }

    public function account(): Account
    {
        return $this->route('account');
    }

    /**
     * @return array<string, int|bool|null>
     */
    public function settings(): array
    {
        $settings = [];

        if ($this->has('minimum_balance')) {
            $settings['minimum_balance'] = $this->filled('minimum_balance') ? (int) $this->input('minimum_balance') : null;
        }

        if ($this->has('annual_rate')) {
            $settings['annual_rate_bps'] = $this->filled('annual_rate') ? (int) round((float) $this->input('annual_rate') * 100) : null;
        }

        if ($this->has('included')) {
            $settings['included'] = $this->boolean('included');
        }

        return $settings;
    }
}
