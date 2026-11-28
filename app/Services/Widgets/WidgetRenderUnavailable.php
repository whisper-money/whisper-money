<?php

namespace App\Services\Widgets;

use RuntimeException;

/**
 * The widget could not be drawn because of an error on our side (the browser
 * is missing or crashed): {@see WidgetRenderer} has already reported the
 * cause, and the message is all the agent gets.
 */
class WidgetRenderUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The widget could not be drawn because of an error on our side. Try again in a moment.');
    }
}
