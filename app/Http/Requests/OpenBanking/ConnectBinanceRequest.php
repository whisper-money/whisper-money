<?php

namespace App\Http\Requests\OpenBanking;

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
            'api_key' => ['required', 'string', 'min:10'],
            'api_secret' => ['required', 'string', 'min:10'],
            'country' => ['required', 'string', 'size:2'],
        ];
    }
}
