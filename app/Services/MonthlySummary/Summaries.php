<?php

namespace App\Services\MonthlySummary;

use App\Enums\MonthlySummaryFormat;
use App\Models\MonthlySummary;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates and freezes the summary for one user and month.
 *
 * A summary is written once. The send window spans eight days and a job can be
 * retried inside it, so everything here is idempotent: the figures are frozen on
 * the first pass, and later passes reuse them rather than quietly reporting a
 * different month than the one the reader was told about.
 *
 * The single exception is a summary frozen while the month was still incomplete
 * and revisited before it was sent — then it is worth refreezing, because the
 * missing bank has since reported.
 */
class Summaries
{
    public function __construct(
        private SummaryBuilder $builder,
        private CardPicker $picker,
        private CardRenderer $renderer,
    ) {}

    public function find(User $user, Carbon $month): ?MonthlySummary
    {
        return $user->monthlySummaries()
            ->where('period', $month->format('Y-m'))
            ->first();
    }

    /**
     * The frozen summary for this month, building it if it does not exist yet.
     * Returns null when the month has nothing worth reporting.
     */
    public function freeze(User $user, Carbon $month, bool $complete): ?MonthlySummary
    {
        $existing = $this->find($user, $month);

        if ($existing !== null && ! $this->worthRefreezing($existing, $complete)) {
            return $existing;
        }

        $payload = $this->builder->build($user, $month, $complete);
        $card = $this->picker->pick(
            $payload,
            $this->builder->previousGoalPercent($user, $month, data_get($payload, 'goal.name')),
        );

        if ($card === null) {
            return null;
        }

        $summary = $existing ?? new MonthlySummary(['user_id' => $user->id, 'period' => $month->format('Y-m')]);
        $summary->fill([
            'space_id' => $user->activeSpace()->id,
            'payload' => $payload,
            'card' => $card,
            'complete' => $complete,
        ])->save();

        if ($existing !== null) {
            // The figures changed, so yesterday's picture is a lie.
            $this->renderer->forget($summary);
        }

        return $summary->refresh();
    }

    /**
     * Draw every card this month can show, and drop the months before it.
     *
     * Called on the way out of a send rather than on a page view: fifteen
     * pictures in one browser take seconds a queue worker has and a web request
     * does not, and by the time the reader opens their report the download
     * buttons have nothing left to do. Last month's pictures go at the same
     * time — that email has been read, and the disk is not an archive.
     */
    public function prepareCards(MonthlySummary $summary, bool $pro): void
    {
        $this->renderer->warm(
            $summary,
            [$summary->card, ...$this->picker->alternatives($summary->payload, $summary->card)],
            $pro,
        );

        $this->renderer->forgetBefore($summary);
    }

    /**
     * Draw the card that rides inside the email. Failing to render must not cost
     * the reader their report, so the caller gets null and the email drops the
     * image rather than the whole send.
     */
    public function primaryCardUrl(MonthlySummary $summary, bool $pro): ?string
    {
        try {
            return $this->renderer->url($summary, $summary->card, MonthlySummaryFormat::default(), $pro);
        } catch (Throwable $exception) {
            report($exception);

            Log::warning('Monthly summary card could not be rendered.', [
                'summary_id' => $summary->id,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Draw the rest of the cards the summary screen shows.
     *
     * The screen puts all five up as 4:5 pictures, and the renderer starts a
     * Chromium run for any it has not drawn yet. Left to the screen, a first
     * visit would start four of them inside four web requests at once; here they
     * cost the one reader whose report this is, on the job that already owns a
     * cold Chromium start.
     *
     * Only the 4:5 shape: nothing shows the story or the wide one, so those are
     * still drawn the first time somebody asks for one.
     */
    public function warmShareCards(MonthlySummary $summary, bool $pro): void
    {
        foreach ($this->picker->alternatives($summary->payload, $summary->card) as $card) {
            try {
                $this->renderer->path($summary, $card, MonthlySummaryFormat::default(), $pro);
            } catch (Throwable $exception) {
                // A picture nobody has asked for yet is not worth failing a send
                // over; the screen draws it on demand if it comes to that.
                report($exception);
            }
        }
    }

    /**
     * A summary that was frozen mid-month and never sent is refrozen once the
     * month completes. One that has already gone out never moves: the reader has
     * the old figures in their inbox.
     */
    private function worthRefreezing(MonthlySummary $summary, bool $complete): bool
    {
        return $summary->sent_at === null && ! $summary->complete && $complete;
    }
}
