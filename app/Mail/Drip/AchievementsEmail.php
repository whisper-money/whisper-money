<?php

namespace App\Mail\Drip;

use App\Enums\CardFormat;
use App\Enums\CardTheme;
use App\Models\Achievement;
use App\Models\User;
use App\Services\Achievements\CardRenderer;
use App\Services\Achievements\Catalog;
use App\Services\Achievements\Ladders;
use App\Services\Achievements\Presenter;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Throwable;

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
     * How many medals the message carries a picture of. Past a handful an email
     * is a download rather than a note, and a sweep that unlocks ten at once is
     * a reader whose history just arrived, not a Tuesday.
     */
    private const MAX_CARDS = 3;

    /** @var array<string, string>|null medal key => its card on the private disk */
    private ?array $cards = null;

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

    /**
     * The cards ride inside the message rather than behind a URL: they are
     * drawn on the private disk on purpose — see {@see CardRenderer} — and an
     * inbox has no session to prove it may read one.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return collect($this->cards())
            ->map(fn (string $path, string $key): Attachment => Attachment::fromStorageDisk(CardRenderer::DISK, $path)
                ->as($this->cid($key))
                ->withMime('image/png'))
            ->values()
            ->all();
    }

    /**
     * The medals as pictures, drawn here rather than in the job so they come
     * out in the language the mailer switched to. Light and 4:5, the shape a
     * card is drawn in everywhere it is not being posted to a story, which is
     * also the file the share dialog serves later: the email warms it.
     *
     * A render that fails costs the picture, not the message.
     *
     * @return array<string, string> medal key => path on {@see CardRenderer::DISK}
     */
    private function cards(): array
    {
        if ($this->cards !== null) {
            return $this->cards;
        }

        $renderer = app(CardRenderer::class);
        $catalog = app(Catalog::class);
        $currency = app(Ladders::class)->currencyFor($this->user->currency_code);

        return $this->cards = $this->achievements
            ->take(self::MAX_CARDS)
            ->mapWithKeys(function (Achievement $achievement) use ($renderer, $catalog, $currency): array {
                $definition = $catalog->find($achievement->key);

                if ($definition === null) {
                    return [];
                }

                try {
                    return [$achievement->key => $renderer->path(
                        $achievement,
                        $definition,
                        $currency,
                        CardFormat::default(),
                        CardTheme::default(),
                        amount: true,
                    )];
                } catch (Throwable $exception) {
                    report($exception);

                    return [];
                }
            })
            ->all();
    }

    /**
     * Symfony turns an attachment into an inline part when the HTML asks for it
     * by name, so the attachment's name is the reference the template writes.
     */
    private function cid(string $key): string
    {
        return $key.'.png';
    }

    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('achievements.unsubscribe', ['user' => $this->user->id]);
    }

    /**
     * One line per medal: what it is called, the milestone it stands for and
     * the tier it belongs to, all written out for the inbox.
     *
     * @return list<array{name: string, milestone: ?string, rarity: string, card: ?string}>
     */
    private function lines(): array
    {
        $catalog = app(Catalog::class);
        $presenter = app(Presenter::class);
        $locale = app()->getLocale();
        $cards = $this->cards();

        return $this->achievements
            ->map(function (Achievement $achievement) use ($catalog, $presenter, $locale, $cards): ?array {
                $definition = $catalog->find($achievement->key);

                if ($definition === null) {
                    return null;
                }

                return [
                    'name' => $definition->name,
                    'milestone' => $presenter->write($presenter->milestone($definition, $this->user->currency_code), $locale),
                    'rarity' => $definition->rarity->label(),
                    'card' => isset($cards[$achievement->key]) ? $this->cid($achievement->key) : null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
