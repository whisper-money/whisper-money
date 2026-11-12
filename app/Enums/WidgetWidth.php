<?php

namespace App\Enums;

/**
 * The fraction of a dashboard row a widget spans, on a 12-column grid: a third
 * is 4 columns, a half 6 and the full row 12, so thirds and halves share it.
 */
enum WidgetWidth: string
{
    case OneThird = '1/3';
    case Half = '1/2';
    case Full = '1/1';
}
