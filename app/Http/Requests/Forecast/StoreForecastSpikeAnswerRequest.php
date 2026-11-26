<?php

namespace App\Http\Requests\Forecast;

use App\Enums\ForecastSpikeAnswer;
use App\Http\Requests\Forecast\Concerns\ScopesForecastEdits;
use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The answer to "¿Fue puntual o se repite?" about one of the user's
 * transactions in the space they are in.
 */
class StoreForecastSpikeAnswerRequest extends FormRequest
{
    use ScopesForecastEdits;

    public function authorize(): bool
    {
        $this->abortUnlessOwned($this->transaction(), $this->transaction()->space_id);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answer' => ['required', Rule::enum(ForecastSpikeAnswer::class)],
        ];
    }

    public function transaction(): Transaction
    {
        return $this->route('transaction');
    }

    public function answer(): ForecastSpikeAnswer
    {
        return ForecastSpikeAnswer::from((string) $this->input('answer'));
    }
}
