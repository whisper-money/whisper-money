<?php

use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Services\Widgets\WidgetRenderer;

/**
 * render_widget draws a widget at the pixel size of its desktop dashboard
 * cell, which WidgetRenderer works out from the grid's measures. Those live
 * in the stylesheet and the grid's classes: this reads them, so a change to
 * the dashboard's layout fails here until the renderer follows, instead of
 * the diagnostics quietly measuring a cell nobody sees.
 */
function dashboardSource(string $path): string
{
    return (string) file_get_contents(__DIR__.'/../../../'.$path);
}

it('sizes each cell as the desktop dashboard grid does', function (WidgetWidth $width, WidgetHeight $height, int $widthPx, ?int $heightPx) {
    $renderer = (new ReflectionClass(WidgetRenderer::class))->newInstanceWithoutConstructor();

    expect($renderer->cellSize($width, $height))->toBe(['width' => $widthPx, 'height' => $heightPx]);
})->with([
    'a third, medium' => [WidgetWidth::OneThird, WidgetHeight::Medium, 395, 256],
    'a half, large' => [WidgetWidth::Half, WidgetHeight::Large, 604, 536],
    'the full row, auto' => [WidgetWidth::Full, WidgetHeight::Auto, 1232, null],
]);

it('works from the measures the dashboard is laid out with', function () {
    expect(dashboardSource('resources/css/theme.css'))->toContain('--container-page: 80rem;')
        ->and(dashboardSource('resources/js/pages/dashboard.tsx'))->toContain('<div className="space-y-6 p-6">')
        ->and(dashboardSource('resources/js/components/dashboard/custom-dashboards/grid-layout.ts'))->toContain(
            "'@container grid grid-cols-12 grid-flow-dense auto-rows-auto gap-6'",
            "'1/3': 'col-span-12 @2xl:col-span-6 @4xl:col-span-4'",
            "'1/2': 'col-span-12 @2xl:col-span-6'",
            "m: 'min-h-64 @2xl:contain-size'",
            "l: 'min-h-[33.5rem] @2xl:contain-size'",
        );
});
