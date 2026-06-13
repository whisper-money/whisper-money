<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;

class ConnectWiseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'api_token' => ['required', 'string', 'min:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_token.required' => 'A Wise API token is required.',
            'api_token.min' => 'The API token appears to be too short.',
        ];
    }
}
