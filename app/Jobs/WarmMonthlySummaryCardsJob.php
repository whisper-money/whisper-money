<?php

namespace App\Jobs;

use App\Models\MonthlySummary;
use App\Services\MonthlySummary\Summaries;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Traits\Localizable;

/**
 * Draws the rest of a month's cards, off the send.
 *
 * The email carries one picture and cannot go out without it, so that one is
 * drawn inside the send. The other twenty-nine — five kinds by three formats by
 * two themes — belong to the report screen, and the screen cannot draw them
 * itself: it paints all five previews at once, so a first visit would start a
 * Chromium run per preview inside as many concurrent web requests as the browser
 * opens.
 *
 * Hence a job of its own, on a queue of its own. A batch is quick when it goes
 * well — eighteen cards measured at 2.3 seconds on a warm machine — but it is
 * one Chromium launch and up to eight seconds of waiting on Google Fonts before
 * the first screenshot, once per reader in the timezone bucket. Inside the send
 * that was time the next reader waited for, the emails worker being a single
 * process; on `default` it would land on one of the two workers that also
 * categorise transactions and run automation rules. Neither queue should have to
 * care: the reader whose report this is has an email to open before any download
 * button matters.
 */
class WarmMonthlySummaryCardsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Localizable, Queueable, SerializesModels;

    /**
     * One attempt. This is a warm cache: a second go costs thirty more renders,
     * and whatever it left undrawn is drawn on demand the first time somebody
     * asks for it anyway.
     */
    public int $tries = 1;

    /**
     * Not how long a batch takes, but longer than the renderer's own ceiling:
     * it allows a batch of thirty 60 + 10 × 30 = 360 seconds before abandoning
     * it, and a job killed before that point leaves a dead process rather than
     * an exception the renderer can shrug off. Stays under the database queue's
     * retry_after, and under the 600-second ceiling config/queue.php sets for
     * any one job's timeout.
     */
    public int $timeout = 600;

    public function __construct(
        public MonthlySummary $summary,
        public bool $pro,
    ) {
        $this->onQueue('cards');
    }

    public function handle(Summaries $summaries): void
    {
        // The copy is baked into the picture, so the language is part of what is
        // drawn — and a queue worker's locale is nobody's in particular.
        $this->withLocale(
            $this->summary->user->preferredLocale(),
            fn () => $summaries->prepareCards($this->summary, $this->pro),
        );
    }
}
