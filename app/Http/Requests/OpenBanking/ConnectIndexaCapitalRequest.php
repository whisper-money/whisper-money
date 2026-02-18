<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Pennant\Feature;

class ConnectIndexaCapitalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Feature::for($this->user())->active('open-banking');
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'api_token' => ['required', 'string', 'min:10'],
        ];
    }
}
