<?php

namespace App\Http\Requests\Forecast;

use App\Enums\ForecastFrequency;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use App\Http\Requests\Forecast\Concerns\ScopesForecastEdits;
use App\Models\Account;
use App\Services\Forecast\Data\NewForecastLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A concrete line the user adds to the forecast: a one-off or yearly charge
 * or income, or a transfer to another of their accounts or out of the app.
 * What repeats every month or so is already in its category's estimate, so
 * only a transfer takes those repetitions. The amount comes positive; its
 * sign follows from the type. It starts today or later, and without Pro
 * within the next month.
 */
class StoreForecastItemRequest extends FormRequest
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
            'type' => ['required', Rule::in(['expense', 'income', 'transfer'])],
            'name' => ['required_unless:type,transfer', 'nullable', 'string', 'max:255'],
            'account_id' => ['required', 'string', $this->includedAccount()],
            'destination_account_id' => ['nullable', 'string', 'prohibited_unless:type,transfer', 'different:account_id', $this->includedAccount()],
            'destination_external' => ['boolean'],
            'category_id' => ['nullable', 'string', 'prohibited_if:type,transfer', $this->userOwned('categories')],
            'amount' => ['required', 'integer', 'min:1', 'max:'.self::MAX_AMOUNT],
            'date' => ['required', 'date_format:Y-m-d'],
            'frequency' => ['required', $this->input('type') === 'transfer'
                ? Rule::enum(ForecastFrequency::class)
                : Rule::enum(ForecastFrequency::class)->only([ForecastFrequency::Once, ForecastFrequency::Yearly])],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->input('type') === 'transfer' && $this->input('destination_account_id') === null && ! $this->boolean('destination_external')) {
                    $validator->errors()->add('destination_account_id', __('Choose where the transfer goes.'));
                }

                if ($this->input('destination_account_id') !== null && ! $this->sameCurrency()) {
                    $validator->errors()->add('destination_account_id', __('A transfer goes between two accounts in the same currency.'));
                }

                if (! $this->editWindow()->allowsNewDate($this->user(), (string) $this->input('date'))) {
                    $validator->errors()->add('date', __('Choose a date from today within the months you can plan.'));
                }
            },
        ];
    }

    private function sameCurrency(): bool
    {
        return Account::query()->whereKey([$this->input('account_id'), $this->input('destination_account_id')])->distinct()->count('currency_code') === 1;
    }

    public function line(): NewForecastLine
    {
        $type = (string) $this->input('type');
        $amount = (int) $this->input('amount');

        return new NewForecastLine(
            accountId: (string) $this->input('account_id'),
            name: trim((string) $this->input('name')) ?: __('Transfer'),
            amount: $type === 'income' ? $amount : -$amount,
            frequency: ForecastFrequency::from((string) $this->input('frequency')),
            anchorDate: (string) $this->input('date'),
            endsOn: $this->input('ends_on'),
            categoryId: $this->input('category_id'),
            destinationAccountId: $this->input('destination_account_id'),
            destinationExternal: $type === 'transfer' && $this->input('destination_account_id') === null,
        );
    }
}
