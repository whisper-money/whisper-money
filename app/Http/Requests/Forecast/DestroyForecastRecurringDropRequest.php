<?php

namespace App\Http\Requests\Forecast;

use App\Http\Requests\Forecast\Concerns\ScopesForecastEdits;
use App\Models\ForecastRecurringDrop;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Undoing "I'm dropping it" on one of the user's charges in the space they
 * are in.
 */
class DestroyForecastRecurringDropRequest extends FormRequest
{
    use ScopesForecastEdits;

    public function authorize(): bool
    {
        $this->abortUnlessOwned($this->drop(), $this->drop()->space_id);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    public function drop(): ForecastRecurringDrop
    {
        return $this->route('drop');
    }
}
