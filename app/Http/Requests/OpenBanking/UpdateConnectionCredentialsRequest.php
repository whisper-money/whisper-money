<?php

namespace App\Http\Requests\OpenBanking;

use App\Models\BankingConnection;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Pennant\Feature;

class UpdateConnectionCredentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $connection = $this->route('connection');

        return Feature::for($this->user())->active('open-banking')
            && $connection instanceof BankingConnection
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
            'indexacapital' => [
                'api_token' => ['required', 'string', 'min:10'],
            ],
            'binance' => [
                'api_key' => ['required', 'string', 'min:10'],
                'api_secret' => ['required', 'string', 'min:10'],
            ],
            'bitpanda' => [
                'api_key' => ['required', 'string', 'min:10'],
            ],
            default => [],
        };
    }
}
