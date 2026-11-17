<?php

namespace App\Rules;

use App\Services\Labels\ArchivedLabelGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses a label id that points at an archived label, unless the target of
 * the write already carries it. Pair it with the rule that checks the id is
 * one of the user's labels: this one passes ids it does not recognise.
 */
class AssignableLabel implements ValidationRule
{
    /** @var list<string>|null */
    private ?array $resolvedAlreadyPresent = null;

    /**
     * @param  Closure(): iterable<mixed>|iterable<mixed>  $alreadyPresent  the label ids the target carries before the write; a closure is only called once an archived label is sent
     */
    public function __construct(private Closure|iterable $alreadyPresent = []) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $labelId = ArchivedLabelGuard::normalize([$value])[0] ?? null;
        $archived = $labelId === null ? null : ArchivedLabelGuard::newlyAddedArchived([$labelId])->first();

        if ($archived !== null && ! in_array($labelId, $this->alreadyPresent(), true)) {
            $fail(ArchivedLabelGuard::message($archived));
        }
    }

    /**
     * @return list<string>
     */
    private function alreadyPresent(): array
    {
        return $this->resolvedAlreadyPresent ??= ArchivedLabelGuard::normalize(
            $this->alreadyPresent instanceof Closure ? ($this->alreadyPresent)() : $this->alreadyPresent,
        );
    }
}
