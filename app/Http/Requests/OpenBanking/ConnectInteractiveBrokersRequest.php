<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;

class ConnectInteractiveBrokersRequest extends FormRequest
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
            'token' => ['required', 'string', 'min:10'],
            'query_id' => ['required', 'string', 'min:3'],
        ];
    }
}
