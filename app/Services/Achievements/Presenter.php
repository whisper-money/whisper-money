<?php

namespace App\Services\Achievements;

use App\Enums\AchievementFigure as Figure;
use App\Models\Achievement;

/**
 * Turns a medal into what the frontend draws.
 *
 * Figures leave here as numbers with a type rather than as sentences, because
 * an amount has to be written by `AmountDisplay` on the client: privacy mode
 * replaces its digits with asterisks, and a figure baked into a server-rendered
 * string would sit there in the clear.
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
     * @return array{type: string, value: int|float, currency: ?string}
     */
    private function figure(Figure $type, int|float $value, ?string $currency): array
    {
        return ['type' => $type->value, 'value' => $value, 'currency' => $currency];
    }
}
