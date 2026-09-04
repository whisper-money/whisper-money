<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Jobs\WarmMonthlySummaryCardsJob;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Notifications\MonthlySummaryReady;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\Summaries;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Traits\Localizable;
use Laravel\Ai\Exceptions\FailoverableException;

/**
 * Sends one frozen monthly summary.
 *
 * The heavy work happens here rather than in the command, so a slow AI call or a
 * cold Chromium start belongs to one reader instead of holding up a whole
 * timezone's worth of sends.
 */
class SendMonthlySummaryEmailJob extends SendDripEmailJob
{
    use Localizable;

    /**
     * Long, because this job waits on the analysis the model writes and on the
     * one card the message carries, which the renderer allows 70 seconds for: a
     * cold Chromium start and a webfont fetch are not instant. Under the
     * worker's 60-second default the send would be killed mid-render, and a
     * killed process is not an exception the renderer can shrug off. The screen's
     * other twenty-nine cards are drawn by {@see WarmMonthlySummaryCardsJob} and
     * cost this job nothing.
     */
    public int $timeout = 300;

    /**
     * Bounded here rather than left to the worker's own `--tries`, because the
     * send holds itself back while the model is unreachable and every hold costs
     * an attempt. Run out of them and the job lands in `failed_jobs`, which
     * would cost the reader the whole report to save the analysis. Four holds is
     * two hours of cover, and the fifth attempt sends whatever it has.
     *
     * It is one budget, not two: an attempt a failing mailer takes is an attempt
     * the analysis cannot hold on to. That trade is the right way round — the
     * mailer failing is what actually stops the reader being written to.
     */
    public int $tries = 5;

    public function __construct(User $user, public MonthlySummary $summary)
    {
        parent::__construct($user);
    }

    protected function emailType(): DripEmailType
    {
        return DripEmailType::MonthlySummary;
    }

    /**
     * One report per reader, space and month. The space is in the key so a future
     * shared space gets its own report instead of silently suppressing one.
     */
    protected function emailIdentifier(): string
    {
        return $this->summary->period.':'.$this->summary->space_id;
    }

    /**
     * Unlike its siblings this one has a side effect: {@see analysisIsSettled()}
     * writes the analysis, and can put the job back on the queue instead of
     * answering. It is here because it is the last thing the parent asks before
     * it sends.
     */
    protected function shouldSend(): bool
    {
        return $this->user->wantsMonthlySummaryEmail() && $this->analysisIsSettled();
    }

    /**
     * Writes the analysis, and says whether the report can go out yet.
     *
     * This is the last guard before the send, which is the only place the
     * question fits: {@see buildMail()} is evaluated as the argument to
     * `Mail::to()->send()`, so a job released from in there would deliver the
     * email regardless. It is also the first place it fits — asking a model
     * about a reader the earlier guards are going to turn away is paid for and
     * thrown out.
     *
     * A reader entitled to an analysis whose model cannot be reached gets the
     * report held rather than an analysis-less one: the analysis has to arrive
     * in the email, not only on the screen. On the last permitted attempt it
     * goes out with the honest "we could not write it" block instead, which is
     * still a report. {@see AnalysisWriter::write()} stores what it writes, so
     * a pass that succeeds is never paid for twice.
     */
    private function analysisIsSettled(): bool
    {
        $writer = app(AnalysisWriter::class);

        if (! $writer->eligible($this->user)) {
            return true;
        }

        try {
            $writer->write($this->summary, $this->user);

            return true;
        } catch (ConnectionException|FailoverableException $exception) {
            if ($this->attempts() >= $this->tries) {
                // Reported here and only here: one Sentry event for a reader who
                // really did lose the analysis, rather than one per pass.
                report($exception);

                return true;
            }

            $this->release(now()->addMinutes(30));

            return false;
        }
    }

    /**
     * The cards are drawn here, so they have to be drawn in the reader's
     * language here too. `Mail::to($user)->send()` does switch the locale for a
     * {@see HasLocalePreference} recipient, but this method is evaluated as the
     * argument to that call — long before the mailer gets a say — so every card
     * came out in the queue worker's English, whatever language the reader
     * reads in.
     */
    protected function buildMail(): Mailable
    {
        return $this->withLocale($this->user->preferredLocale(), fn (): Mailable => $this->reportEmail());
    }

    private function reportEmail(): Mailable
    {
        $pro = app(AnalysisWriter::class)->eligible($this->user);

        $mail = new MonthlySummaryEmail(
            $this->user,
            $this->summary,
            // Already written by shouldSend(), or given up on there.
            $this->summary->ai_analysis,
            // The one card the message carries, drawn here because the message
            // cannot go out without it.
            app(Summaries::class)->primaryCardUrl($this->summary, $pro),
            $pro,
            $this->spaceName(),
        );

        // The screen's other twenty-nine, and last month's thrown away, on a job
        // of their own: the reader has a message to read before any of the
        // download buttons matter, and this worker has the next reader waiting.
        WarmMonthlySummaryCardsJob::dispatch($this->summary, $pro);

        // The bell rings once per report, however often the send is retried. The
        // row itself is the record: `sent_at` is written after this point, so a
        // job that died in between would ring again if it were the guard.
        $alreadyRung = $this->user->notifications()
            ->where('type', MonthlySummaryReady::class)
            ->where('data->summary_id', $this->summary->id)
            ->exists();

        if (! $alreadyRung) {
            $this->user->notify(new MonthlySummaryReady($this->summary));
        }

        $this->summary->forceFill(['sent_at' => now()])->save();

        return $mail;
    }

    /**
     * Only named when the reader can see more than one space. With a single
     * space the label would just be a word nobody asked about — and today every
     * user has exactly one.
     */
    private function spaceName(): ?string
    {
        if ($this->user->accessibleSpaces()->count() < 2) {
            return null;
        }

        return $this->summary->space?->name;
    }
}
