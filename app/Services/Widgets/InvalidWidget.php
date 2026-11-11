<?php

namespace App\Services\Widgets;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * A widget, or a change to one, that was not saved, with every reason why in
 * the one shape the agent that wrote it reads: where (`manifest.size.width`,
 * `source:12:5`), what is wrong, and sometimes what to do about it.
 *
 * @api its errors are what the MCP widget tools, in a follow-up, hand to the agent
 */
class InvalidWidget extends RuntimeException
{
    /**
     * @param  non-empty-list<array{path: string, message: string, hint?: string}>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct($errors[0]['message']);
    }

    public static function at(string $path, string $message, ?string $hint = null): self
    {
        return new self([array_filter(['path' => $path, 'message' => $message, 'hint' => $hint], fn (?string $value): bool => $value !== null)]);
    }

    /**
     * The same errors for a web form, under `$field`, each with where it is and what to do.
     */
    public function toValidationException(string $field): ValidationException
    {
        return ValidationException::withMessages([
            $field => array_map(
                fn (array $error): string => trim("{$error['path']}: {$error['message']} ".($error['hint'] ?? '')),
                $this->errors,
            ),
        ]);
    }
}
