<?php

namespace App\Enums;

/**
 * A widget's height on a dashboard. `m` (16rem) and `l` (33.5rem, two `m`
 * rows and the gap between them) are fixed: content that does not fit scrolls
 * inside the cell. `a` takes the height of its content, for widgets that have
 * one of their own (a row of KPI tiles, text); a chart has none and needs `m`
 * or `l`. On a wide dashboard the widgets of one row always share its height:
 * the tallest one sets it and the others stretch to it.
 */
enum WidgetHeight: string
{
    case Medium = 'm';
    case Large = 'l';
    case Auto = 'a';

    /**
     * Every height, as stored and as the agent writes it.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The fixed heights, the only ones the system widgets admit: they all
     * fill their cell instead of having a height of their own.
     *
     * @return list<self>
     */
    public static function fixed(): array
    {
        return [self::Medium, self::Large];
    }
}
