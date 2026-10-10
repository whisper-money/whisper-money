<?php

namespace App\Http\Requests\Concerns;

/**
 * The total, starting balance and date of a one-off savings goal, shared by the
 * create and edit forms and the MCP tools.
 */
trait ValidatesOneOffSavingsTarget
{
    /**
     * @param  bool  $creating  a new goal needs a total; an edit may leave it alone
     * @return array<string, list<string>>
     */
    protected function oneOffTargetRules(bool $creating): array
    {
        return [
            'target_amount' => [...($creating ? [] : ['sometimes']), 'required', 'integer', 'min:1'],
            'initial_amount' => [...($creating ? ['nullable'] : ['sometimes', 'required']), 'integer', 'min:0'],
            // Pin the format and bound the year: 'date' alone silently mangles a
            // five-digit year typo like 20026-11-10 into 2006-11-10, so every
            // range rule sees a plausible date while the raw string is what
            // reaches MySQL and blows up as an out-of-range date (PHP-LARAVEL-5X).
            // The date picker always sends Y-m-d, and 2100 rejects typos rather
            // than real target dates. A new goal's date must lie ahead; an edit
            // has no `after:today`, because a goal whose date has passed still
            // has to be editable, so 1900 is the floor that keeps a
            // dropped-digit typo like 0026-11-10 out.
            'target_date' => [
                'sometimes',
                'nullable',
                'date_format:Y-m-d',
                $creating ? 'after:today' : 'after_or_equal:1900-01-01',
                'before_or_equal:2100-01-01',
            ],
        ];
    }
}
