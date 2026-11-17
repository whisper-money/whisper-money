<?php

namespace App\Services\Labels;

use App\Models\Label;
use App\Rules\AssignableLabel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An archived label stays on whatever already carries it, but is never added
 * anew: not to a transaction, an automation rule or a new budget. Every write
 * path asks here (the form requests through {@see AssignableLabel}), so a
 * label that is already present can be kept — submitting the same set again
 * on an edit never fails — and only a newly added archived label is refused.
 */
class ArchivedLabelGuard
{
    /**
     * The archived labels among $labelIds that $alreadyPresent does not hold.
     *
     * @param  iterable<mixed>  $labelIds
     * @param  iterable<mixed>  $alreadyPresent  the labels the target carries before the write
     * @return Collection<int, Label>
     */
    public static function newlyAddedArchived(iterable $labelIds, iterable $alreadyPresent = []): Collection
    {
        $added = array_values(array_diff(self::normalize($labelIds), self::normalize($alreadyPresent)));

        if ($added === []) {
            return new Collection;
        }

        return Label::query()->archived()->whereKey($added)->get(['id', 'name']);
    }

    /**
     * Refuse the write, under $attribute, when it adds an archived label.
     *
     * @param  iterable<mixed>  $labelIds
     * @param  iterable<mixed>  $alreadyPresent
     *
     * @throws ValidationException
     */
    public static function ensureAssignable(iterable $labelIds, iterable $alreadyPresent = [], string $attribute = 'label_ids'): void
    {
        $archived = self::newlyAddedArchived($labelIds, $alreadyPresent)->first();

        if ($archived !== null) {
            throw ValidationException::withMessages([$attribute => self::message($archived)]);
        }
    }

    public static function message(Label $label): string
    {
        return __('The label “:name” is archived, so it cannot be added. Unarchive it first to use it again.', ['name' => $label->name]);
    }

    /**
     * The uuids among $ids, lowercased, so ids sent in any case compare equal.
     *
     * @param  iterable<mixed>  $ids
     * @return list<string>
     */
    public static function normalize(iterable $ids): array
    {
        $normalized = [];

        foreach ($ids as $id) {
            if (is_string($id) && Str::isUuid($id)) {
                $normalized[] = mb_strtolower($id);
            }
        }

        return array_values(array_unique($normalized));
    }
}
