<?php

namespace App\Enums;

/**
 * A widget's height: `l` is exactly twice `m`, so one large widget lines up
 * with two medium ones stacked beside it.
 */
enum WidgetHeight: string
{
    case Medium = 'm';
    case Large = 'l';
}
