<?php

namespace App\Enums;

/**
 * What the dashboard draws around a user widget: its card, or nothing, for a
 * widget that draws its own cards (a row of KPI tiles) on the dashboard's
 * background.
 */
enum WidgetFrame: string
{
    case Card = 'card';
    case None = 'none';
}
