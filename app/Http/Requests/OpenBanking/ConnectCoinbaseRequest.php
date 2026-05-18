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
            'api_key_name' => ['required', 'string', 'regex:/^(organizations\/[a-z0-9-]+\/apiKeys\/[a-z0-9-]+|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/i'],
            'private_key' => ['required', 'string', 'min:40'],
            'country' => ['required', 'string', 'size:2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'api_key_name.regex' => 'The App Key ID must be a valid UUID (Ed25519) or organizations/{org_id}/apiKeys/{key_id} (ECDSA).',
            'private_key.min' => 'The Secret looks too short. Paste the full secret from Coinbase.',
        ];
    }
}
