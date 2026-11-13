<?php

use App\Enums\SystemWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;

/**
 * The server validates system widgets against {@see SystemWidget}; the page
 * draws them from `SYSTEM_WIDGETS` in the TypeScript registry. This reads the
 * registry's source so the two cannot drift: a widget added, renamed or
 * resized on one side fails here until the other side follows.
 */
function systemWidgetSource(string $file): string
{
    return file_get_contents(__DIR__.'/../../resources/js/components/dashboard/widgets/'.$file);
}

/**
 * Each registry entry's id, default size and allowed sizes, with the shared
 * size lists (`HALF_OR_FULL`, `WIDGET_WIDTHS`…) resolved to their values.
 *
 * @return array<string, array{default: array{string, string}, widths: list<string>, heights: list<string>}>
 */
function typescriptSystemWidgets(): array
{
    $source = systemWidgetSource('registry.ts').systemWidgetSource('types.ts');

    preg_match_all('/(?:const|export const) (\w+)(?:: [^=]+)? = (\[[^\]]*\])(?: as const)?;/', $source, $constants, PREG_SET_ORDER);
    $lists = [];
    foreach ($constants as [, $name, $list]) {
        $lists[$name] = $list;
    }

    $resolve = function (string $expression) use ($lists): array {
        preg_match_all("/'([^']+)'/", $lists[$expression] ?? $expression, $values);

        return $values[1];
    };

    preg_match_all(
        "/id: '([^']+)',\s*defaultSize: \{ width: '([^']+)', height: '([^']+)' \},\s*allowedWidths: ([^,]+),\s*allowedHeights: ([^,]+|\[[^\]]*\]),/",
        $source,
        $entries,
        PREG_SET_ORDER,
    );

    $widgets = [];
    foreach ($entries as [, $id, $width, $height, $widths, $heights]) {
        $widgets[$id] = [
            'default' => [$width, $height],
            'widths' => $resolve(trim($widths)),
            'heights' => $resolve(trim($heights)),
        ];
    }

    return $widgets;
}

it('lists the same system widgets as the TypeScript registry, in the same order', function () {
    preg_match('/SYSTEM_WIDGET_IDS = \[([^\]]*)\]/', systemWidgetSource('registry.ts'), $match);
    preg_match_all("/'([^']+)'/", $match[1], $ids);

    expect(array_column(SystemWidget::cases(), 'value'))->toBe($ids[1])
        ->and(array_keys(typescriptSystemWidgets()))->toEqualCanonicalizing($ids[1]);
});

it('gives each system widget the sizes the TypeScript registry gives it', function () {
    $widgets = typescriptSystemWidgets();

    foreach (SystemWidget::cases() as $widget) {
        $default = $widget->defaultSize();

        expect($widgets[$widget->value])->toBe([
            'default' => [$default['width']->value, $default['height']->value],
            'widths' => array_map(fn (WidgetWidth $width): string => $width->value, $widget->allowedWidths()),
            'heights' => array_map(fn (WidgetHeight $height): string => $height->value, $widget->allowedHeights()),
        ], $widget->value);
    }
});

it('offers the same sizes as the TypeScript types', function () {
    $types = systemWidgetSource('types.ts');

    preg_match('/type WidgetWidth = ([^;]+);/', $types, $widths);
    preg_match('/type WidgetHeight = ([^;]+);/', $types, $heights);
    preg_match_all("/'([^']+)'/", $widths[1], $widthValues);
    preg_match_all("/'([^']+)'/", $heights[1], $heightValues);

    expect(array_column(WidgetWidth::cases(), 'value'))->toBe($widthValues[1])
        ->and(array_column(WidgetHeight::cases(), 'value'))->toBe($heightValues[1]);
});
