<?php

namespace App\Mcp\Resources;

use App\Mcp\Tools\Concerns\PresentsWidgets;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\AppResource;
use Laravel\Mcp\Server\Attributes\AppMeta;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Uri;

/**
 * The MCP App that draws a widget inside the chat, right after
 * create_widget, update_widget or get_widget: the same SDK, module registry,
 * error boundary and stylesheet as the dashboard, fed with the payload those
 * tools put in their result's `_meta` ({@see PresentsWidgets}), so the data
 * is the user's own, resolved through the gateway.
 *
 * The chat client loads it into a sandboxed iframe with no session in the
 * app and a CSP that allows inline code only, so the HTML carries the whole
 * preview, built by vite.widget-preview.config.ts.
 */
#[Uri('ui://whisper-money/widget-preview')]
#[Description('A live preview of a dashboard widget, drawn with the user\'s own data as the dashboard would.')]
#[AppMeta(prefersBorder: true)]
class WidgetPreview extends AppResource
{
    private const string BUILD_DIRECTORY = 'build/widget-preview';

    public function handle(Request $request): Response
    {
        return Response::view('mcp.widget-preview', [
            'title' => 'Widget preview',
            'script' => $this->asset('widget-preview.js', '</script'),
            'style' => $this->asset('widget-preview.css', '</style'),
        ]);
    }

    /**
     * A built file, safe to inline: no sequence in it can close the tag that
     * holds it. Null when the preview has not been built.
     */
    private function asset(string $file, string $closingTag): ?string
    {
        $path = public_path(self::BUILD_DIRECTORY.'/'.$file);

        if (! is_file($path)) {
            return null;
        }

        return str_ireplace($closingTag, '<\\'.substr($closingTag, 1), (string) file_get_contents($path));
    }
}
