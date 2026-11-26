<?php

namespace App\Http\Requests\Forecast;

use App\Enums\ForecastFrequency;
use App\Services\Forecast\ForecastAccounts;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The answers of the forecast's review: the suggestions kept as lines (each
 * one or several suggestions merged, optionally renamed, and for a transfer
 * the app cannot see the other side of, the account it goes to), the
 * suggestions left out, and the yearly or one-off payments typed in, with a
 * signed amount in the account's minor units. Accounts must be ones the
 * forecast includes, so another user's ids are refused.
 */
class StoreForecastReviewRequest extends FormRequest
{
    private const MAX_AMOUNT = 1_000_000_000_000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $accounts = Rule::in(app(ForecastAccounts::class)->included($this->user(), $this->user()->activeSpace()->id)->modelKeys());

        return [
            'lines' => ['present', 'array', 'max:200'],
            'lines.*.keys' => ['required', 'array', 'min:1', 'max:10'],
            'lines.*.keys.*' => ['required', 'string', 'max:1000', 'distinct'],
            'lines.*.name' => ['nullable', 'string', 'max:255'],
            'lines.*.destinationAccountId' => ['nullable', 'string', $accounts],
            'dismissed' => ['present', 'array', 'max:400'],
            'dismissed.*' => ['string', 'max:1000'],
            'manual' => ['present', 'array', 'max:50'],
            'manual.*.name' => ['required', 'string', 'max:255'],
            'manual.*.amount' => ['required', 'integer', 'not_in:0', 'between:-'.self::MAX_AMOUNT.','.self::MAX_AMOUNT],
            'manual.*.month' => ['required', 'integer', 'between:1,12'],
            'manual.*.accountId' => ['required', 'string', $accounts],
            'manual.*.frequency' => ['required', Rule::enum(ForecastFrequency::class)->only([ForecastFrequency::Yearly, ForecastFrequency::Once])],
        ];
    }

    /**
     * @return array{lines: list<array{keys: list<string>, name?: string|null, destinationAccountId?: string|null}>, dismissed: list<string>, manual: list<array{name: string, amount: int, month: int, accountId: string, frequency: string}>}
     */
    public function answers(): array
    {
        /** @var array{lines: list<array{keys: list<string>, name?: string|null, destinationAccountId?: string|null}>, dismissed: list<string>, manual: list<array{name: string, amount: int, month: int, accountId: string, frequency: string}>} $answers */
        $answers = $this->validated();

        return $answers;
    }
}
