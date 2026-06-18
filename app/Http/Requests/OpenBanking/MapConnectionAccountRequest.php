<?php

namespace App\Http\Requests\OpenBanking;

use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Foundation\Http\FormRequest;

class MapConnectionAccountRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

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
            'bank_account_uid' => ['required', 'string'],
            'action' => ['required', 'in:create,link'],
            'existing_account_id' => [
                'nullable',
                'uuid',
                'required_if:action,link',
                $this->userOwned('accounts'),
            ],
            'currency' => ['nullable', 'string', 'size:3'],
            'name' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:34'],
        ];
    }
}
