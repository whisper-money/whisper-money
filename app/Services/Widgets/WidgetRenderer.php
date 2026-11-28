<?php

namespace App\Services\Widgets;

use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Mcp\Resources\WidgetPreview;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Browser\HeadlessChromium;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use JsonException;
use RuntimeException;

/**
 * Draws one of the user's widgets in headless Chromium, with their data, as
 * the desktop dashboard would, and says what came out: its markup, compacted,
 * and what is wrong with its layout, measured in the browser
 * (resources/js/widgets/preview/layout-diagnostics.ts). render_widget answers
 * with it, so the agent that wrote the widget can see the result.
 *
 * The page is the widget preview MCP App's own HTML ({@see WidgetPreview}) fed
 * with {@see WidgetPreviewPayload}, opened by scripts/render-widget.mjs. The
 * agent's TSX runs only inside that browser, which may reach nothing on the
 * network.
 *
 * It runs synchronously, inside the MCP request: the agent reads the answer
 * in the same turn. The timeout keeps a stuck browser from holding it.
 */
class WidgetRenderer
{
    private const string SCRIPT = 'scripts/render-widget.mjs';

    /**
     * The desktop dashboard's cell, in CSS pixels. The page is capped at 80rem
     * with 1.5rem of padding each side, so the grid is 1232px wide: twelve
     * columns with 1.5rem gaps (grid-layout.ts), a third spanning 4 and a half 6.
     */
    private const array WIDTHS = ['1/3' => 395, '1/2' => 604, '1/1' => 1232];

    /** `m` is 16rem and `l` 33.5rem; `a` is as tall as its content. */
    private const array HEIGHTS = ['m' => 256, 'l' => 536, 'a' => null];

    /** Past this the markup is cut, and the answer says so. */
    private const int MAX_HTML_LENGTH = 30_000;

    public function __construct(
        private WidgetPreviewPayload $payloads,
        private WidgetPreview $preview,
        private HeadlessChromium $chromium,
    ) {}

    /**
     * @return array{status: string, html: ?string, html_truncated: bool, diagnostics: list<array{kind: string, message: string, element?: string}>, errors: list<array<string, mixed>>, console: list<array{type: string, text: string}>, data_failures: list<array{key: string, status: int, message: string}>, size: array{width: string, height: string, width_px: int, height_px: ?int, content_height_px: ?int}}
     *
     * @throws WidgetRenderUnavailable when the browser broke, not the widget
     */
    public function render(User $user, WidgetDefinition $widget, string $theme, ?WidgetWidth $width = null, ?WidgetHeight $height = null): array
    {
        $payload = $this->payloads->for($user, $widget);
        $size = [
            'width' => $width?->value ?? $payload['size']['width'],
            'height' => $height?->value ?? $payload['size']['height'],
        ];
        $payload['size'] = $size;
        $cell = ['width' => self::WIDTHS[$size['width']], 'height' => self::HEIGHTS[$size['height']]];

        $page = $this->preview->document()
            ?? throw $this->unavailable('the widget preview is not built (public/build/widget-preview); run `bun run build`');

        $output = $this->draw($page, $payload, $theme, $cell);

        return [
            'status' => $output['timed_out'] ? 'timeout' : (string) $output['status'],
            'html' => $output['html'] ?? null,
            'html_truncated' => (bool) ($output['truncated'] ?? false),
            'diagnostics' => $output['diagnostics'] ?? [],
            'errors' => $output['timed_out'] ? [[
                'phase' => 'timeout',
                'message' => 'The widget did not finish drawing in time. Look for a loop that never ends or a render that never settles.',
            ]] : $output['errors'],
            'console' => $output['console'] ?? [],
            'data_failures' => $payload['failures'],
            'size' => [
                ...$size,
                'width_px' => $cell['width'],
                'height_px' => $cell['height'],
                'content_height_px' => $output['content_height'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{width: int, height: ?int}  $cell
     * @return array<string, mixed>
     */
    private function draw(string $page, array $payload, string $theme, array $cell): array
    {
        $timeoutSeconds = config()->integer('widgets.renderer.timeout');
        $html = tempnam(sys_get_temp_dir(), 'wm-widget-').'.html';
        $job = tempnam(sys_get_temp_dir(), 'wm-widget-job-');

        try {
            file_put_contents($html, $page);
            file_put_contents($job, json_encode([
                'html' => $html,
                'payload' => $payload,
                'theme' => $theme,
                'cell' => $cell,
                'max_html_length' => self::MAX_HTML_LENGTH,
                // The browser answers with what it has before PHP gives up on it.
                'timeout_ms' => $timeoutSeconds * 1000,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            $result = $this->chromium->run(self::SCRIPT, $job, $timeoutSeconds + 15);
        } catch (ProcessTimedOutException) {
            throw $this->unavailable("the browser did not answer within {$timeoutSeconds}s");
        } catch (JsonException $exception) {
            throw $this->unavailable('the payload could not be encoded: '.$exception->getMessage());
        } finally {
            @unlink($html);
            @unlink($job);
        }

        if ($result->failed()) {
            // stderr is Playwright's (a missing browser, a crash), never the
            // page's: what the widget logs is collected, not printed.
            throw $this->unavailable("exit code {$result->exitCode()}: ".mb_substr(trim($result->errorOutput()), 0, 500));
        }

        return $this->parse($result->output());
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $output): array
    {
        try {
            $parsed = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw $this->unavailable('unreadable output: '.$exception->getMessage());
        }

        if (! is_array($parsed) || ! is_bool($parsed['timed_out'] ?? null)
            || (! $parsed['timed_out'] && (! is_string($parsed['status'] ?? null) || ! is_array($parsed['errors'] ?? null)))) {
            throw $this->unavailable('unexpected output shape');
        }

        return $parsed;
    }

    /**
     * The renderer itself broke, not the widget: report it to us and give the
     * agent nothing it could act on.
     */
    private function unavailable(string $reason): WidgetRenderUnavailable
    {
        report(new RuntimeException("Widget renderer crashed: {$reason}"));

        return new WidgetRenderUnavailable;
    }
}
