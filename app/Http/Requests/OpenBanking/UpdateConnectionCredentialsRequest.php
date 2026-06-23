<?php

namespace App\Http\Requests\OpenBanking;

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

        return $connection->provider->credentialRules();
    }
}
