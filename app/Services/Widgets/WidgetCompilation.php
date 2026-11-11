<?php

namespace App\Services\Widgets;

/**
 * The outcome of compiling one widget: the ES module to store, or the errors
 * to hand back to the agent that wrote it. Errors carry the position in the
 * TSX and a message that says what to use instead.
 *
 * @api Read by widget saving in a follow-up; see WidgetCompiler.
 */
final readonly class WidgetCompilation
{
    /**
     * @param  list<array{line: int, column: int, code: string, message: string}>  $errors
     * @param  array{validateMs?: float, buildMs?: float}  $timings  empty when the compiler never answered
     */
    public function __construct(
        public bool $ok,
        public ?string $js,
        public array $errors,
        public array $timings = [],
    ) {}

    public static function failed(string $code, string $message): self
    {
        return new self(false, null, [[
            'line' => 1,
            'column' => 1,
            'code' => $code,
            'message' => $message,
        ]]);
    }
}
