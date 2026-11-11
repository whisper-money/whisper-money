<?php

namespace App\Services\Widgets;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

/**
 * Validates and compiles a widget's TSX into the ES module the dashboard
 * loads, by running scripts/widgets/compile-widget.ts under Bun.
 *
 * It runs synchronously, not in a job: create_widget and update_widget answer
 * with the validation errors, so the agent can fix them in the same turn. The
 * timeout keeps a stuck compile from holding the request.
 *
 * @api Consumed by widget saving (create_widget/update_widget) in a follow-up;
 *      until then only the test suite, which static analysis does not scan.
 */
class WidgetCompiler
{
    private const SCRIPT = 'scripts/widgets/compile-widget.ts';

    private readonly string $script;

    private readonly int $timeoutSeconds;

    public function __construct(?string $script = null, ?int $timeoutSeconds = null)
    {
        $this->script = $script ?? base_path(self::SCRIPT);
        $this->timeoutSeconds = $timeoutSeconds ?? config()->integer('widgets.compiler.timeout');
    }

    public function compile(string $source): WidgetCompilation
    {
        try {
            $result = Process::path(base_path())
                ->timeout($this->timeoutSeconds)
                ->input(json_encode(['source' => $source], JSON_THROW_ON_ERROR))
                ->run([config()->string('widgets.compiler.bun_binary'), $this->script]);
        } catch (ProcessTimedOutException) {
            return WidgetCompilation::failed(
                'timeout',
                "Compiling the widget took longer than {$this->timeoutSeconds}s. Simplify the component and try again.",
            );
        } catch (JsonException) {
            return WidgetCompilation::failed('encoding', 'The widget source is not valid UTF-8.');
        }

        if ($result->failed()) {
            // stderr is left out on purpose: Bun prints the widget source with
            // its stack traces, and user code does not belong in error reports.
            return $this->crashed("exit code {$result->exitCode()}");
        }

        return $this->parse($result->output());
    }

    private function parse(string $output): WidgetCompilation
    {
        try {
            $payload = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return $this->crashed('unreadable output: '.$exception->getMessage());
        }

        if (! is_array($payload) || ! is_bool($payload['ok'] ?? null) || ! is_array($payload['errors'] ?? null)) {
            return $this->crashed('unexpected output shape');
        }

        /** @var array{ok: bool, js: ?string, errors: list<array{line: int, column: int, code: string, message: string}>, timings: array{validateMs: float, buildMs: float}} $payload */
        return new WidgetCompilation(
            ok: $payload['ok'],
            js: $payload['js'],
            errors: $payload['errors'],
            timings: $payload['timings'],
        );
    }

    /**
     * The compiler itself broke, not the widget: report it to us and give the
     * agent nothing it could act on.
     */
    private function crashed(string $reason): WidgetCompilation
    {
        report(new RuntimeException("Widget compiler crashed: {$reason}"));

        return WidgetCompilation::failed(
            'compiler',
            'The widget could not be compiled because of an error on our side. Try again in a moment.',
        );
    }
}
