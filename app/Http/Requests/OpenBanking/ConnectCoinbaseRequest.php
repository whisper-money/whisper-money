<?php

namespace App\Http\Requests\OpenBanking;

use App\Enums\BankingProvider;
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
            ...BankingProvider::Coinbase->credentialRules(),
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
