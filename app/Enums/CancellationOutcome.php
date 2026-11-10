<?php

namespace App\Enums;

/**
 * How a run of the cancel flow ended. A flow the user walked away from has no
 * outcome at all.
 */
enum CancellationOutcome: string
{
    case Canceled = 'canceled';

    case Saved = 'saved';
}
