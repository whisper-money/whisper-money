<?php

namespace App\Enums;

/**
 * What the number on a medal is, so the frontend knows how to write it: an
 * amount goes through `AmountDisplay` and masks in privacy mode, a percentage
 * gets its sign, a count of months, weeks or days gets its word. `None` is an
 * event with no number at all, such as the first bank connected.
 */
enum AchievementFigure: string
{
    case Money = 'money';
    case Percent = 'percent';
    case Months = 'months';
    case Weeks = 'weeks';
    case Days = 'days';
    case Count = 'count';
    case None = 'none';
}
