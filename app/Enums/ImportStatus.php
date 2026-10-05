<?php

namespace App\Enums;

/**
 * Where a full import is: still receiving its rows, waiting for a worker,
 * running, or finished one way or the other.
 */
enum ImportStatus: string
{
    case Draft = 'draft';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Being taken back out of the user's data by UndoImportJob. */
    case Undoing = 'undoing';

    /** Whether a worker has the import, or is about to. */
    public function isRunning(): bool
    {
        return $this === self::Queued || $this === self::Processing;
    }

    /** Whether the import got as far as writing anything it could leave behind. */
    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }
}
