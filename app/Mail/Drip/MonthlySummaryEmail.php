<?php

namespace App\Mail\Drip;

use App\Enums\MonthlySummaryCard;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\CardPicker;
use App\Services\MonthlySummary\EmailPresenter;
use App\Support\Figures;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * The monthly report.
 *
 * A view rather than a Markdown mail, because the design is a report and not a
 * letter. Everything it prints comes from the frozen summary, so re-sending or
 * previewing it can never produce different figures than the reader was given.
 */
class MonthlySummaryEmail extends DripMail
{
    /**
     * @param  ?string  $analysis  the AI analysis, or null when the reader is not entitled to one or the model failed
     * @param  ?string  $cardUrl  the rendered card, or null when rendering failed — the report goes out either way
     */
    public function __construct(
        User $user,
        public MonthlySummary $summary,
        public ?string $analysis = null,
        public ?string $cardUrl = null,
        public bool $pro = false,
        public ?string $spaceName = null,
    ) {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        $rate = (float) $this->summary->figure('cashflow.savings_rate', 0);
        $month = $this->monthName();

        if ($rate <= 0) {
            return __('Your :month summary', ['month' => $month]);
        }

        return __('You saved :rate in :month', [
            'rate' => Figures::percent($rate, app()->getLocale()),
            'month' => $month,
        ]);
    }

    protected function template(): string
    {
        return 'mail.monthly-summary';
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

    public function content(): Content
    {
        $report = app(EmailPresenter::class)->present($this->summary, app()->getLocale(), $this->pro);

        return new Content(
            view: $this->template(),
            with: [
                ...$report,
                'subject' => $this->dripSubject(),
                'preheader' => $this->preheader(),
                'appUrl' => rtrim((string) config('app.url'), '/'),
                'complete' => $this->summary->complete,
                'incompleteNotice' => $this->incompleteNotice(),
                'spaceName' => $this->spaceName,
                'analysis' => $this->analysis,
                'lockedPitch' => $this->lockedPitch(),
                'lockedAction' => $this->lockedAction(),
                'lockedUrl' => $this->lockedUrl(),
                'cardUrl' => $this->cardUrl,
                'cardAlt' => __('Your :month card', ['month' => $this->monthName()]),
                'shareUrl' => $this->withUtm(route('monthly-summaries.show', $this->summary), 'share'),
                'shareBlurb' => __('Your :month in one image: percentages and streaks, not a single amount.', ['month' => $this->monthName()]),
                'alternatives' => $this->alternatives(),
                'historyUrl' => $this->withUtm(route('monthly-summaries.index'), 'history'),
                'preferencesUrl' => $this->withUtm(route('notifications.index'), 'preferences'),
                'unsubscribeUrl' => $this->unsubscribeUrl(),
                'todos' => $this->todosWithUrls($report['todos']),
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $todos
     * @return list<array<string, mixed>>
     */
    private function todosWithUrls(array $todos): array
    {
        return array_map(fn (array $todo): array => [
            ...$todo,
            'url' => $this->withUtm(route($todo['route']), 'todo'),
        ], $todos);
    }

    /**
     * The other cards this month can produce, so the reader knows the picture is
     * a choice rather than the only thing on offer.
     *
     * @return list<array<string, mixed>>
     */
    private function alternatives(): array
    {
        $cards = app(CardPicker::class)->alternatives($this->summary->payload, $this->summary->card);

        return array_map(fn (MonthlySummaryCard $card): array => [
            'label' => $this->cardLabel($card),
            'url' => $this->withUtm(route('monthly-summaries.show', $this->summary), 'card-'.$card->value),
        ], $cards);
    }

    private function cardLabel(MonthlySummaryCard $card): string
    {
        return match ($card) {
            MonthlySummaryCard::Streak => __('Streak'),
            MonthlySummaryCard::SavingsRate => __('Savings rate'),
            MonthlySummaryCard::SpendingSplit => __('Where it went'),
            MonthlySummaryCard::NetWorth => __('Net worth'),
            MonthlySummaryCard::SavingsGoal => __('Savings goal'),
        };
    }

    /**
     * The line the inbox shows before anything is opened. It names the card,
     * which is the cheapest place for it to exist.
     */
    private function preheader(): string
    {
        return __('…and your :month card, ready to share.', ['month' => $this->monthName()]);
    }

    /**
     * Said out loud when the month was reported without every source having
     * checked in, so a figure that looks low has an explanation attached.
     */
    private function incompleteNotice(): string
    {
        return __('Worked out on :date. If you have imported or synced anything since, the app has the fuller picture.', [
            'date' => $this->summary->created_at?->locale(app()->getLocale())->isoFormat('D MMMM') ?? '',
        ]);
    }

    private function lockedPitch(): string
    {
        return __('That figure did not come out of nowhere, and Pro tells you where from: what moved it, what is going to repeat next month, and which budget is about to fall short.');
    }

    private function lockedAction(): string
    {
        return $this->user->hasProPlan()
            ? __('Turn AI on in Settings')
            : __('See Pro');
    }

    private function lockedUrl(): string
    {
        return $this->withUtm(
            $this->user->hasProPlan() ? route('notifications.index') : route('subscribe'),
            'locked-analysis',
        );
    }

    /**
     * A permanent signed link: the reader may act on it months after the send,
     * and an expired unsubscribe link is worse than no link at all.
     */
    private function unsubscribeUrl(): string
    {
        return URL::signedRoute('monthly-summaries.unsubscribe', ['user' => $this->user->id]);
    }

    private function monthName(): string
    {
        return $this->summary->periodStart()->locale(app()->getLocale())->isoFormat('MMMM');
    }

    /**
     * @param  string  $content  distinguishes the links this mail carries
     */
    private function withUtm(string $url, string $content): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            ...$this->utmParameters(),
            'utm_content' => $content,
        ]);
    }
}
