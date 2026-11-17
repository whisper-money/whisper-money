<?php

namespace App\Rules;

use App\Models\Label;
use App\Services\Labels\LabelWriter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A label name is unique among the user's labels that are not deleted. When
 * the clash is with an archived label the message says so, since unarchiving
 * it is what the user most likely wants.
 */
class AvailableLabelName implements ValidationRule
{
    public function __construct(private string $userId, private ?Label $ignore = null) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $clash = Label::query()
            ->where('user_id', $this->userId)
            ->where('name', $value)
            ->when($this->ignore !== null, fn ($query) => $query->whereKeyNot($this->ignore->getKey()))
            ->first(['id', 'name', 'archived_at']);

        if ($clash === null) {
            return;
        }

        if ($clash->isArchived()) {
            $fail(LabelWriter::archivedNameClashMessage($clash));

            return;
        }

        $fail('validation.unique')->translate();
    }
}
