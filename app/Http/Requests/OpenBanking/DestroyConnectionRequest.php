<?php

namespace App\Http\Requests\OpenBanking;

use Illuminate\Foundation\Http\FormRequest;

class DestroyConnectionRequest extends FormRequest
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
            'delete_accounts' => ['required', 'boolean'],
            'confirmation' => ['required_if:delete_accounts,true', 'nullable', 'string', 'in:delete all'],
        ];
    }
}
