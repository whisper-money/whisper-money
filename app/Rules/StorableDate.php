<?php

namespace App\Rules;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Keeps a date inside the years a MySQL DATE column stores and a person can
 * mean, so an out-of-range year is a 422 instead of a 500 on save.
 *
 * Laravel's `date` rule alone is not enough, and neither are `after`/`before`:
 * PHP's parser reads a six-digit year like 262004-08-10 as 2004-08-10, so every
 * range rule sees a plausible date while the raw string is what reaches MySQL
 * (PHP-LARAVEL-7D, PHP-LARAVEL-7E). This rule compares the year written in the
 * value with the one PHP parsed, and rejects years before 1900 or after 9999.
 * It accepts the same formats as `date` (Y-m-d, ISO datetimes, ...), so pair it
 * with `date`, which reports values that are not dates at all.
 */
class StorableDate implements ValidationRule
{
    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 9999;

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $year = $this->year($value);

        if ($year !== null && ($year < self::MIN_YEAR || $year > self::MAX_YEAR)) {
            $fail(__('The :attribute must be a date between :min and :max.', [
                'min' => self::MIN_YEAR,
                'max' => self::MAX_YEAR,
            ]));
        }
    }

    /**
     * The year the value means, or null when it is not a date (left to `date`).
     * A leading run of four or more digits before a separator is the year as written,
     * which wins over the parsed one whenever PHP truncated it (d-m-Y keeps its
     * parsed year).
     */
    private function year(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return (int) $value->format('Y');
        }

        if (! is_string($value)) {
            return null;
        }

        if (preg_match('/^\s*(\d{4,})[-\/.]/', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        $parsed = date_parse($value);

        return $parsed['error_count'] === 0 && is_int($parsed['year']) ? $parsed['year'] : null;
    }
}
