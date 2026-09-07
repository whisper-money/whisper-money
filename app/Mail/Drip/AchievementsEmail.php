<?php

namespace App\Mail\Drip;

use App\Models\Achievement;
use App\Models\User;
use App\Services\Achievements\Catalog;
use App\Services\Achievements\Presenter;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * The day's medals, in one message.
 *
 * One email per sweep, however many medals it found: three separate
 * congratulations in an inbox on the same morning is spam, and the reader
 * already has the three rows waiting in the bell.
 *
 * Amounts are written out here, unlike on screen where privacy mode can hide
 * them: an inbox is already the reader's own, and a message about money that
 * will not say the money is not worth sending.
 */
class AchievementsEmail extends DripMail
{
    /**
     * @param  Collection<int, Achievement>  $achievements
     */
    public function __construct(User $user, public Collection $achievements)
    {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        if ($this->achievements->count() === 1) {
            return __('You unlocked :achievement', ['achievement' => $this->lines()[0]['name']]);
        }

        return __('You unlocked :count achievements', ['count' => $this->achievements->count()]);
    }

    protected function template(): string
    {
        return 'mail.drip.achievements';
    }

    /**
     * A one-click unsubscribe header, so a reader who has had enough can act
     * from the mailbox and the provider keeps delivering to everyone else.
     */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        return [
            'lines' => $this->lines(),
            'unsubscribeUrl' => $this->unsubscribeUrl(),
        ];
    }

    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('achievements.unsubscribe', ['user' => $this->user->id]);
    }

    /**
     * One line per medal: what it is called, the milestone it stands for and
     * the tier it belongs to, all written out for the inbox.
     *
     * @return list<array{name: string, milestone: ?string, rarity: string}>
     */
    private function lines(): array
    {
        $catalog = app(Catalog::class);
        $presenter = app(Presenter::class);
        $locale = app()->getLocale();

        return $this->achievements
            ->map(function (Achievement $achievement) use ($catalog, $presenter, $locale): ?array {
                $definition = $catalog->find($achievement->key);

                if ($definition === null) {
                    return null;
                }

                return [
                    'name' => $definition->name,
                    'milestone' => $presenter->write($presenter->milestone($definition, $this->user->currency_code), $locale),
                    'rarity' => $definition->rarity->label(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
