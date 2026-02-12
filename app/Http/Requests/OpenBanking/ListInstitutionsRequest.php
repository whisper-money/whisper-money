<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Pennant\Feature;

class ListInstitutionsRequest extends FormRequest
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
            'country' => ['required', 'string', 'size:2'],
        ];
    }
}
