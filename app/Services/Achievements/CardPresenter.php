<?php

namespace App\Services\Achievements;

use App\Enums\AchievementFigure as Figure;
use App\Enums\CardFormat;
use App\Enums\CardTheme;
use App\Support\Figures;
use App\Support\Money;
use Carbon\Carbon;

/**
 * Everything `cards.achievement` needs to draw one medal.
 *
 * Unlike the screen, a card is a picture: the figure arrives here as a finished
 * sentence rather than as a typed number, because there is no `AmountDisplay`
 * inside a PNG and nothing to mask once it is posted. That is the whole reason
 * `$amount` exists — see {@see viewData()}.
 */
class CardPresenter
{
    public function __construct(
        private Catalog $catalog,
        private Presenter $presenter,
        private Pictograms $pictograms,
    ) {}

    /**
     * @param  bool  $amount  Whether a money medal writes its figure on the
     *                        card. Shown by default; the share dialog can
     *                        withhold it, which is the only way an amount stays
     *                        out of a picture the reader is about to publish.
     *                        Medals whose figure is a rate, a run of months or
     *                        a count ignore this: those are safe to post, and
     *                        the summary cards have shown them from the start.
     * @return array<string, mixed>
     */
    public function viewData(
        Definition $definition,
        Carbon $achievedOn,
        string $currency,
        CardFormat $format,
        CardTheme $theme,
        bool $amount,
    ): array {
        $locale = app()->getLocale();
        $metal = $definition->rarity->metal();
        $edge = $metal['bezel']?->metal() ?? $metal;

        return [
            'format' => $format,
            'theme' => $theme,
            'rarity' => $definition->rarity,
            'glyph' => $this->pictograms->path($definition->icon),
            'track' => $this->catalog->tracks()[$definition->track] ?? $definition->track,
            'name' => $definition->name,
            'figure' => $this->figure($definition, $currency, $locale, $amount),
            'tier' => $definition->rarity->label(),
            // Derived from the medal's own metal rather than from a second table
            // of label colours: on paper the struck edge reads, on a dark card
            // the lit face does. Obsidian speaks through its gold crown.
            'tierColour' => $theme->isDark() ? $edge['light'] : $edge['rim'],
            'when' => $this->monthLabel($achievedOn, $locale),
        ];
    }

    /**
     * The milestone as it is written on the card, or null when there is nothing
     * to write — an event medal has no number, and a money medal whose reader
     * asked to keep the amount out has none either.
     */
    private function figure(Definition $definition, string $currency, string $locale, bool $amount): ?string
    {
        $milestone = $this->presenter->milestone($definition, $currency);

        if ($milestone === null) {
            return null;
        }

        $value = $milestone['value'];

        return match ($milestone['type']) {
            Figure::Money->value => $amount && $milestone['currency'] !== null
                ? Money::formatIn((int) $value, $milestone['currency'], $locale, decimals: 0)
                : null,
            // A milestone is a round rung — 20, 30, 50, 75 — so it is written
            // without decimals, the same as the screen writes it.
            Figure::Percent->value => Figures::percent((float) $value, $locale, decimals: 0),
            Figure::Months->value => trans_choice(':count month|:count months', (int) $value, ['count' => Figures::count((int) $value, $locale)]),
            Figure::Weeks->value => trans_choice(':count week|:count weeks', (int) $value, ['count' => Figures::count((int) $value, $locale)]),
            Figure::Days->value => trans_choice(':count day|:count days', (int) $value, ['count' => Figures::count((int) $value, $locale)]),
            default => Figures::count((int) $value, $locale),
        };
    }

    private function monthLabel(Carbon $achievedOn, string $locale): string
    {
        return $achievedOn->locale($locale)->isoFormat('MMMM YYYY');
    }
}
