<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'notify_on_new_transaction' => ['sometimes', 'boolean'],
            'notify_on_close_to_limit' => ['sometimes', 'boolean'],
            'notify_on_over_limit' => ['sometimes', 'boolean'],
        ];
    }
}
