<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DismissTrialBannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The `dismissalKey` of the banner the user closed, as the shared prop
     * handed it down.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'dismissal_key' => ['required', 'string', 'max:20'],
        ];
    }
}
