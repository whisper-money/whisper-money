<?php

namespace App\Actions;

use App\Models\Label;

/**
 * Archiving a label records when it happened and nothing else: the label
 * stays on every transaction, rule and budget that already uses it, and keeps
 * working in filters and reports. It only stops being assignable anew (see
 * ArchivedLabelGuard). Unarchiving clears the date.
 *
 * Archiving an already archived label keeps the original date.
 */
class SetLabelArchived
{
    public function handle(Label $label, bool $archived): Label
    {
        $this->fill($label, $archived)->save();

        return $label;
    }

    /**
     * Set the date without saving, for a caller that saves other changes to
     * the label in the same write.
     */
    public function fill(Label $label, bool $archived): Label
    {
        $label->archived_at = $archived ? ($label->archived_at ?? now()) : null;

        return $label;
    }
}
