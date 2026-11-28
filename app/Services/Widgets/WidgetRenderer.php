<?php

namespace App\Services\Widgets;

use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Mcp\Resources\WidgetPreview;
use App\Models\User;
use App\Models\WidgetDefinition;
use App\Services\Browser\HeadlessChromium;
use Closure;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Cache;
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
     * The desktop dashboard's grid, in CSS pixels: the page is capped at 80rem
     * (`max-w-page`) with 1.5rem of padding each side (`p-6`), and the grid has
     * twelve columns with 1.5rem gaps (`grid-cols-12 gap-6`, grid-layout.ts), a
     * third spanning 4 and a half 6. WidgetCellSizeTest fails when they drift.
     */
    private const int GRID_WIDTH = 1280 - 2 * 24;

    private const int GAP = 24;

    private const int COLUMNS = 12;

    private const array SPANS = ['1/3' => 4, '1/2' => 6, '1/1' => 12];

    /** `m` is 16rem and `l` 33.5rem; `a` is as tall as its content. */
    private const array HEIGHTS = ['m' => 256, 'l' => 536, 'a' => null];

    /** A render holds a browser and a web worker: one per user at a time. */
    private const int LOCK_SECONDS_PAST_TIMEOUT = 30;

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
     * @throws WidgetRenderUnavailable when the browser broke or is busy, not the widget
     */
    public function render(User $user, WidgetDefinition $widget, string $theme, ?WidgetWidth $width = null, ?WidgetHeight $height = null): array
    {
        $payload = $this->payloads->for($user, $widget);
        $size = [
            'width' => $width?->value ?? $payload['size']['width'],
            'height' => $height?->value ?? $payload['size']['height'],
        ];
        $payload['size'] = $size;
        $cell = $this->cellSize(WidgetWidth::from($size['width']), WidgetHeight::from($size['height']));

        $page = $this->preview->document()
            ?? throw $this->unavailable('the widget preview is not built (public/build/widget-preview); run `bun run build`');

        $output = $this->withBrowserSlot($user, fn (): array => $this->draw($page, $payload, $theme, $cell));

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
     * The cell the desktop dashboard gives a widget of this size, in CSS
     * pixels; a null height grows with the content.
     *
     * @return array{width: int, height: ?int}
     */
    public function cellSize(WidgetWidth $width, WidgetHeight $height): array
    {
        $column = (self::GRID_WIDTH - (self::COLUMNS - 1) * self::GAP) / self::COLUMNS;
        $span = self::SPANS[$width->value];

        return [
            'width' => (int) round($span * $column + ($span - 1) * self::GAP),
            'height' => self::HEIGHTS[$height->value],
        ];
    }

    /**
     * Run $draw holding the user's render lock and one of the server's
     * browser slots: every render is a Chromium process inside a web request,
     * so an agent looping on the tool, or many at once, must not take the
     * workers with them.
     *
     * @template T
     *
     * @param  Closure(): T  $draw
     * @return T
     */
    private function withBrowserSlot(User $user, Closure $draw): mixed
    {
        $seconds = config()->integer('widgets.renderer.timeout') + self::LOCK_SECONDS_PAST_TIMEOUT;
        $own = Cache::lock("widget-render:user:{$user->id}", $seconds);

        if (! $own->get()) {
            throw WidgetRenderUnavailable::busy('Another of this user\'s widgets is being drawn. Wait for it to finish, then try again.');
        }

        try {
            foreach (range(1, max(1, config()->integer('widgets.renderer.concurrency'))) as $slot) {
                $lock = Cache::lock("widget-render:slot:{$slot}", $seconds);

                if ($lock->get()) {
                    try {
                        return $draw();
                    } finally {
                        $lock->release();
                    }
                }
            }

            throw WidgetRenderUnavailable::busy('Too many widgets are being drawn right now. Try again in a few seconds.');
        } finally {
            $own->release();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{width: int, height: ?int}  $cell
     * @return array<string, mixed>
     */
    private function draw(string $page, array $payload, string $theme, array $cell): array
    {
        $timeoutSeconds = config()->integer('widgets.renderer.timeout');
        $job = tempnam(sys_get_temp_dir(), 'wm-widget-')
            ?: throw $this->unavailable('no temporary file could be created');
        $html = $job.'.html';

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

        if (! is_array($parsed) || ! is_bool($parsed['timed_out'] ?? null) || ! $this->wellFormed($parsed)) {
            throw $this->unavailable('unexpected output shape');
        }

        return $parsed;
    }

    /**
     * What render-mode.tsx's inspection answers; a timeout carries none of it.
     *
     * @param  array<mixed>  $parsed
     */
    private function wellFormed(array $parsed): bool
    {
        if ($parsed['timed_out']) {
            return true;
        }

        return is_string($parsed['status'] ?? null)
            && is_string($parsed['html'] ?? null)
            && is_int($parsed['content_height'] ?? null)
            && is_array($parsed['diagnostics'] ?? null)
            && is_array($parsed['errors'] ?? null)
            && array_filter($parsed['errors'], fn (mixed $error): bool => ! is_array($error)) === [];
    }

    /**
     * The renderer itself broke, not the widget: report it to us and give the
     * agent nothing it could act on.
     */
    private function unavailable(string $reason): WidgetRenderUnavailable
    {
        report(new RuntimeException("Widget renderer crashed: {$reason}"));

        return WidgetRenderUnavailable::crashed();
    }
}
