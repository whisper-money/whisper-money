<?php

namespace App\Http\Requests\Forecast;

use App\Http\Requests\Forecast\Concerns\ScopesForecastEdits;
use App\Models\ForecastItem;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An action on one of the user's lines that takes no input: stop
 * forecasting it, or accept the update its last payments suggest.
 */
class ForecastItemActionRequest extends FormRequest
{
    use ScopesForecastEdits;

    public function authorize(): bool
    {
        $this->abortUnlessEditableItem($this->item());

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function item(): ForecastItem
    {
        return $this->route('item');
    }
}
