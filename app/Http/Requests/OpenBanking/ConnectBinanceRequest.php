<?php

namespace App\Http\Requests\OpenBanking;

use App\Enums\BankingProvider;
use Illuminate\Foundation\Http\FormRequest;

class ConnectBinanceRequest extends FormRequest
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
            ...BankingProvider::Binance->credentialRules(),
            'country' => ['required', 'string', 'size:2'],
        ];
    }
}
