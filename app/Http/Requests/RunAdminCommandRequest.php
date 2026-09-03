<?php

namespace App\Http\Requests;

use App\Http\Controllers\AdminController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunAdminCommandRequest extends FormRequest
{
    /**
     * The route is already behind the `admin` middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'command' => ['required', 'string', Rule::in(AdminController::allowedCommands())],
            // Appended to the chosen command and parsed by Symfony's StringInput,
            // never by a shell. The command name is the first token either way,
            // so this can add options and values but cannot change what runs;
            // the character allowlist keeps it to that shape.
            'arguments' => ['nullable', 'string', 'max:200', 'regex:/^[\w\-=@.:\/+ ]*$/'],
        ];
    }
}
