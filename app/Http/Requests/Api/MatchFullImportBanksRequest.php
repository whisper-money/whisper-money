<?php

namespace App\Http\Requests\Api;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The account names of a file, for the wizard to learn which bank each one
 * belongs to.
 */
class MatchFullImportBanksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->canUseFullImport();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'names' => ['required', 'array', 'max:200'],
            'names.*' => ['required', 'string', 'max:255'],
        ];
    }
}
