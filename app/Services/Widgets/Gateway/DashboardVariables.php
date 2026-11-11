<?php

namespace App\Services\Widgets\Gateway;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The dashboard's variables a manifest's parameters may reference as
 * `{{range.from}}`: the date range the dashboard shows. The page can change
 * them; the endpoints and the other parameters stay as the manifest saved
 * them.
 *
 * Built by the widget data routes from the dashboard's range.
 */
final readonly class DashboardVariables
{
    /**
     * A `{{name}}` reference.
     */
    private const string Reference = '/\{\{\s*([^{}]*?)\s*\}\}/';

    public const array Names = ['range.from', 'range.to'];

    public function __construct(
        private CarbonImmutable $from,
        private CarbonImmutable $to,
    ) {}

    /**
     * The whole month $day falls in: pass the user's own today
     * ({@see User::localToday()}), not the server's.
     */
    public static function forMonthOf(CarbonImmutable $day): self
    {
        return new self($day->startOfMonth(), $day->endOfMonth()->startOfDay());
    }

    /**
     * The variables $value references, known or not.
     *
     * @return list<string>
     */
    public static function referencedIn(string $value): array
    {
        preg_match_all(self::Reference, $value, $matches);

        return $matches[1];
    }

    /**
     * $value with every reference in it, or in the strings it holds, replaced by the variable's value.
     */
    public function fill(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map($this->fill(...), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        $values = $this->values();

        return preg_replace_callback(self::Reference, fn (array $match): string => $values[$match[1]] ?? $match[0], $value);
    }

    /**
     * @return array<string, string>
     */
    public function values(): array
    {
        return [
            'range.from' => $this->from->toDateString(),
            'range.to' => $this->to->toDateString(),
        ];
    }
}
