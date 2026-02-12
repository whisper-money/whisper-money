<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Pennant\Feature;

class StartAuthorizationRequest extends FormRequest
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
            'aspsp_name' => ['required', 'string'],
            'country' => ['required', 'string', 'size:2'],
            'logo' => ['nullable', 'string', 'url'],
        ];
    }
}
