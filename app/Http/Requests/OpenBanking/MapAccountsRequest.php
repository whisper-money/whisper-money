<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MapAccountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('connection')->user_id === $this->user()->id;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*.bank_account_uid' => ['required', 'string'],
            'mappings.*.action' => ['required', 'in:create,link,skip'],
            'mappings.*.existing_account_id' => [
                'nullable',
                'uuid',
                'required_if:mappings.*.action,link',
                Rule::exists('accounts', 'id')->where('user_id', $this->user()->id),
            ],
        ];
    }
}
