<?php

namespace App\Enums;

/**
 * What checking a stored widget again under the current rules did
 * (App\Services\Widgets\WidgetDefinitionService::recheck()).
 */
enum WidgetRecheck: string
{
    /** Already checked under the current rules: nothing to do. */
    case Current = 'current';

    /** It still passes: its module was compiled again and stored. */
    case Recompiled = 'recompiled';

    /** It no longer passes: its module was replaced by an inert one. */
    case Neutralised = 'neutralised';

    /** The compiler could not judge it: served inert, checked again later. */
    case Unavailable = 'unavailable';
}
