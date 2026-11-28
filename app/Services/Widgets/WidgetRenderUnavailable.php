<?php

namespace App\Services\Widgets;

use RuntimeException;

/**
 * The widget could not be drawn for a reason that is not the widget's: the
 * browser is missing or crashed ({@see WidgetRenderer} has already reported
 * the cause), or every browser slot is taken. The message is all the agent
 * gets.
 */
class WidgetRenderUnavailable extends RuntimeException
{
    public static function crashed(): self
    {
        return new self('The widget could not be drawn because of an error on our side. Try once more later; if it keeps failing, carry on without render_widget.');
    }

    public static function busy(string $message): self
    {
        return new self($message);
    }
}
