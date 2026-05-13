<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;

class ConnectCoinbaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'api_key_name' => ['required', 'string', 'regex:/^organizations\/[a-z0-9-]+\/apiKeys\/[a-z0-9-]+$/i'],
            'private_key' => ['required', 'string', 'min:100'],
            'country' => ['required', 'string', 'size:2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_key_name.regex' => 'The API key name must be in the format organizations/{org_id}/apiKeys/{key_id}.',
            'private_key.min' => 'The private key must be a complete PEM-encoded EC private key.',
        ];
    }
}
