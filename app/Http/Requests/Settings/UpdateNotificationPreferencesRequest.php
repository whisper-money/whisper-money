<?php

namespace App\Http\Requests\Settings;

use App\Http\Controllers\Settings\NotificationPreferenceController;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        $allowedKeys = implode(',', array_keys(NotificationPreferenceController::PREFERENCES));

        return [
            'notifications' => ['required', 'array:'.$allowedKeys],
            'notifications.*' => ['required', 'boolean'],
        ];
    }
}
