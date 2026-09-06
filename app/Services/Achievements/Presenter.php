<?php

namespace App\Services\Achievements;

use App\Enums\AchievementFigure as Figure;
use App\Models\Achievement;
use App\Support\Figures;
use App\Support\Money;

/**
 * Turns a medal into what the frontend draws.
 *
 * Figures leave here as numbers with a type rather than as sentences, because
 * an amount has to be written by `AmountDisplay` on the client: privacy mode
 * replaces its digits with asterisks, and a figure baked into a server-rendered
 * string would sit there in the clear. {@see write()} is the way out for the
 * surfaces that have no client to defer to.
 */
class Presenter
{
    public function __construct(private Ladders $ladders) {}

    /**
     * The milestone the medal stands for: the rung of the ladder, the rate, the
     * count. Null for the medals that are an event with no number to them.
     *
     * @return array{type: string, value: int|float, currency: ?string}|null
     */
    public function milestone(Definition $definition, string $currency): ?array
    {
        $ladder = $definition->ladder();

        if ($ladder !== null) {
            $rung = $this->ladders->rung($ladder, $definition->tier, $currency);

            return $rung === null ? null : $this->figure(Figure::Money, $rung, $this->ladders->currencyFor($currency));
        }

        if ($definition->threshold === null || $definition->figure === Figure::None) {
            return null;
        }

        return $this->figure($definition->figure, $definition->threshold, null);
    }

    /**
     * What the reader actually reached, as it was on the day. Which column the
     * row filled says how to read it.
     *
     * @return array{type: string, value: int|float, currency: ?string}|null
     */
    public function reached(Achievement $achievement): ?array
    {
        if ($achievement->percent !== null) {
            return $this->figure(Figure::Percent, $achievement->percent, null);
        }

        if ($achievement->value === null) {
            return null;
        }

        return $achievement->currency_code === null
            ? $this->figure(Figure::Count, $achievement->value, null)
            : $this->figure(Figure::Money, $achievement->value, $achievement->currency_code);
    }

    /**
     * The same figure written out in the clear, for the places that cannot
     * defer to `AmountDisplay`: the inbox, and the monthly report that prints
     * its amounts server-side the way its other sentences do.
     *
     * Everything with a unit carries it, because these land inside a sentence
     * where a bare number says nothing: "Visit streak, 30" is not a milestone.
     *
     * @param  array{type: string, value: int|float, currency: ?string}|null  $figure
     */
    public function write(?array $figure, string $locale): ?string
    {
        return match (true) {
            $figure === null => null,
            $figure['currency'] !== null => Money::formatIn((int) $figure['value'], $figure['currency'], $locale),
            $figure['type'] === 'percent' => Figures::percent((float) $figure['value'], $locale, decimals: 0),
            $figure['type'] === 'months' => trans_choice('{1}1 month|[2,*]:count months', (int) $figure['value'], ['count' => (int) $figure['value']]),
            $figure['type'] === 'days' => trans_choice('{1}1 day|[2,*]:count days', (int) $figure['value'], ['count' => (int) $figure['value']]),
            $figure['type'] === 'weeks' => trans_choice('{1}1 week|[2,*]:count weeks', (int) $figure['value'], ['count' => (int) $figure['value']]),
            default => Figures::count((int) $figure['value'], $locale),
        };
    }

    /**
     * @return array{type: string, value: int|float, currency: ?string}
     */
    private function figure(Figure $type, int|float $value, ?string $currency): array
    {
        return ['type' => $type->value, 'value' => $value, 'currency' => $currency];
    }
}
