<?php

namespace App\Jobs\Drip;

use App\Enums\DripEmailType;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\AnalysisWriter;
use App\Services\MonthlySummary\Summaries;
use Illuminate\Mail\Mailable;

/**
 * Sends one frozen monthly summary.
 *
 * The heavy work happens here rather than in the command, so a slow AI call or a
 * cold Chromium start belongs to one reader instead of holding up a whole
 * timezone's worth of sends.
 */
class SendMonthlySummaryEmailJob extends SendDripEmailJob
{
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

    protected function shouldSend(): bool
    {
        return $this->user->wantsMonthlySummaryEmail();
    }

    protected function buildMail(): Mailable
    {
        $pro = app(AnalysisWriter::class)->eligible($this->user);

        $mail = new MonthlySummaryEmail(
            $this->user,
            $this->summary,
            app(AnalysisWriter::class)->write($this->summary, $this->user),
            app(Summaries::class)->primaryCardUrl($this->summary, $pro),
            $pro,
            $this->spaceName(),
        );

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
