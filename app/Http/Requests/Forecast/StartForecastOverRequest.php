<?php

namespace App\Http\Requests\Forecast;

use Illuminate\Foundation\Http\FormRequest;

/**
 * "Start over" on the forecast: it takes no input, and only ever touches the
 * signed-in user's own rows in the space they are in.
 */
class StartForecastOverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [];
    }
}
