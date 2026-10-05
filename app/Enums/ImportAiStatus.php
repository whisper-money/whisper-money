<?php

namespace App\Enums;

/**
 * What happens to the movements a full import left uncategorized.
 */
enum ImportAiStatus: string
{
    /** Nothing was left uncategorized. */
    case Skipped = 'skipped';

    /** The user is still onboarding: the onboarding's own AI step covers it. */
    case Onboarding = 'onboarding';

    /** No paid plan or no consent: they stay uncategorized. */
    case Unavailable = 'unavailable';

    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
}
