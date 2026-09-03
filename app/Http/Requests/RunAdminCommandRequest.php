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
        $allowed = AdminController::allowedCommands();

        return [
            'command' => ['required', 'string', Rule::in(array_keys($allowed))],
            // Appended to the chosen command and parsed by Symfony's StringInput,
            // never by a shell. The command name is the first token either way,
            // so this can add options and values but cannot change what runs;
            // the character allowlist keeps it to that shape.
            //
            // Which command it lands on still matters: `demo:reset --email=` is
            // a delete, so the commands that take no arguments reject any.
            'arguments' => [
                Rule::prohibitedIf(fn (): bool => ! ($allowed[$this->input('command')] ?? false)),
                'nullable',
                'string',
                'max:200',
                'regex:/^[\w\-=@.:\/+ ]*$/',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'arguments.prohibited' => 'This command does not take arguments.',
            'arguments.regex' => 'Arguments may only contain letters, digits and - = @ . : / + _',
        ];
    }
}
