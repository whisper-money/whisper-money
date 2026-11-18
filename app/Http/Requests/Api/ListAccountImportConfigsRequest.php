<?php

namespace App\Http\Requests\Api;

use App\Enums\ImportConfigType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAccountImportConfigsRequest extends FormRequest
{
    /**
     * Only the user's own accounts are listed, so any signed-in user may ask.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ImportConfigType::class)],
        ];
    }
}
