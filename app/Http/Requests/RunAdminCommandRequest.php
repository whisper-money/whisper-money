<?php

namespace App\Http\Requests;

use App\Http\Controllers\AdminController;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RunAdminCommandRequest extends FormRequest
{
    /**
     * What the free-text arguments may be made of. Wide enough for the shapes
     * the curated commands actually take - a quoted bank name, a feature class
     * with its backslashes, a percentage rollout - and no wider. Symfony's
     * StringInput parses this, never a shell, so the characters a shell would
     * act on are simply not needed here.
     */
    private const ARGUMENTS_PATTERN = <<<'REGEX'
        /^[\w \-=@.:\/+%\\"',]*$/u
        REGEX;

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
                'regex:'.self::ARGUMENTS_PATTERN,
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
            'arguments.regex' => 'Arguments may only contain letters, digits, quotes and - = @ . : / + % \\ , _',
        ];
    }
}
