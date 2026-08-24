<?php

namespace App\Http\Requests\Api;

use App\Enums\ImportConfigType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountImportConfigRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via the AccountPolicy.
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
            'config' => ['required', 'array'],
            'config.columnMapping' => ['required', 'array'],
            'config.dateFormat' => ['required', 'string', 'max:20'],
        ];
    }
}
