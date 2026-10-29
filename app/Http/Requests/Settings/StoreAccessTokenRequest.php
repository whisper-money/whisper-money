<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What every personal access token form asks for: a name and its access
 * (`read`, or `read_write`).
 */
abstract class StoreAccessTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'scope' => ['required', 'in:read,read_write'],
            ...$this->surfaceRules(),
        ];
    }

    /**
     * Rules for the settings only one surface's tokens have.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function surfaceRules(): array
    {
        return [];
    }
}
