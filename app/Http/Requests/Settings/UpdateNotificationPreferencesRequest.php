<?php

namespace App\Http\Requests\Settings;

use App\Features\Forecast;
use App\Http\Controllers\Settings\NotificationPreferenceController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Pennant\Feature;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $keys = array_keys(NotificationPreferenceController::PREFERENCES);

        if (! Feature::for($this->user())->active(Forecast::class)) {
            $keys = array_diff($keys, NotificationPreferenceController::FORECAST_PREFERENCES);
        }

        $allowedKeys = implode(',', $keys);

        return [
            'notifications' => ['required', 'array:'.$allowedKeys],
            'notifications.*' => ['required', 'boolean'],
        ];
    }
}
