<?php

namespace App\Contracts;

use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;

/**
 * A widget that can be placed on a dashboard: a system widget, or one the
 * user's agent wrote, whose manifest names the sizes it admits.
 */
interface SizedWidget
{
    /** @return array{width: WidgetWidth, height: WidgetHeight} */
    public function defaultSize(): array;

    public function allowsSize(WidgetWidth $width, WidgetHeight $height): bool;
}
