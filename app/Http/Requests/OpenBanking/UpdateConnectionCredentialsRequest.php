<?php

namespace App\Http\Requests\OpenBanking;

use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use Illuminate\Foundation\Http\FormRequest;

class UpdateConnectionCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return $connection instanceof BankingConnection
            && $connection->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $connection = $this->route('connection');

        if (! $connection instanceof BankingConnection) {
            return [];
        }

        return match ($connection->provider) {
            BankingProvider::IndexaCapital => [
                'api_token' => ['required', 'string', 'min:10'],
            ],
            BankingProvider::Binance => [
                'api_key' => ['required', 'string', 'min:10'],
                'api_secret' => ['required', 'string', 'min:10'],
            ],
            BankingProvider::Bitpanda => [
                'api_key' => ['required', 'string', 'min:10'],
            ],
            BankingProvider::Coinbase => [
                'api_key_name' => ['required', 'string', 'regex:/^(organizations\/[a-z0-9-]+\/apiKeys\/[a-z0-9-]+|[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/i'],
                'private_key' => ['required', 'string', 'min:40'],
            ],
            default => [],
        };
    }
}
