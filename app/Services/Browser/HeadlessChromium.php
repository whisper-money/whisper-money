<?php

namespace App\Services\Browser;

use App\Services\Cards\CardBrowser;
use App\Services\Widgets\WidgetRenderer;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

/**
 * Runs one of the scripts that drive headless Chromium through Playwright
 * (scripts/lib/chromium.mjs starts the browser): the card photographs
 * ({@see CardBrowser}) and render_widget
 * ({@see WidgetRenderer}). What both need to get right
 * about the subprocess, the runtime and the HOME Chromium may write to, is
 * solved here once.
 */
class HeadlessChromium
{
    /**
     * Run $script (relative to the project) with $argument under the
     * JavaScript runtime the production image carries.
     */
    public function run(string $script, string $argument, int $timeoutSeconds): ProcessResult
    {
        return Process::env($this->environment())
            ->timeout($timeoutSeconds)
            ->run([
                // Named after the first thing it drew; the cards and widgets share it.
                (string) config('monthly_summary.node_binary'),
                base_path($script),
                $argument,
            ]);
    }

    /**
     * What the render subprocess is given on top of what it inherits.
     *
     * Chromium keeps its crash database under $HOME, and without a writable one
     * the crash handler exits before the browser is usable —
     * `chrome_crashpad_handler: --database is required`, then SIGTRAP. php-fpm
     * does not hand its workers the environment the image sets, so fixing it in
     * the container only ever fixed the queue worker: every card drawn inside a
     * web request still died.
     *
     * But HOME is also where Playwright looks for the browsers it installed, so
     * moving it unconditionally hides them from itself — which is why this never
     * worked on a developer's machine, where nothing sets
     * PLAYWRIGHT_BROWSERS_PATH and the real HOME is the only place the browser
     * exists. Production pins that variable in the image, so there the override
     * was harmless and the bug stayed invisible.
     *
     * So: only move HOME when the one we have cannot do the job. A writable HOME
     * is exactly what the crash handler needs and exactly what Playwright needs
     * left alone.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $home = (string) getenv('HOME');

        if ($home !== '' && is_writable($home)) {
            return [];
        }

        return ['HOME' => sys_get_temp_dir()];
    }
}
