<?php

namespace App\Mail;

use App\Services\Forecast\ForecastAlertNotifier;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sent once per new strong forecast alert: one of the user's accounts could
 * drop below zero, or below the minimum they want to keep, in a coming month.
 * It names the month and how far off it is, and nothing else: no amount and no
 * account, for the same reason the monthly summary carries none.
 *
 * The alerts were claimed before this was queued; if delivery gives up for
 * good, the claims are released so the next run sends it again.
 */
class ForecastAlertEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @var int
     */
    public $tries = 5;

    /**
     * @var array<int, int>
     */
    public $backoff = [2, 5, 10, 30];

    /**
     * @param  string  $month  `Y-m`, the earliest month short.
     * @param  int  $monthsAway  Whole months from the user's month in progress to it.
     * @param  bool  $belowMinimum  The balance stays above zero but under the wanted minimum.
     * @param  list<string>  $alertIds
     */
    public function __construct(
        public string $month,
        public int $monthsAway,
        public bool $belowMinimum,
        public array $alertIds,
    ) {
        $this->onQueue('emails');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.from.address', 'no-reply@whisper.money'),
                config('mail.from.name', 'Whisper Money'),
            ),
            subject: __('One of your accounts could run short in :month', ['month' => $this->monthDate()->isoFormat('MMMM')]),
        );
    }

    public function content(): Content
    {
        $month = $this->monthDate();

        return new Content(
            markdown: 'mail.forecast-alert',
            with: [
                'monthWithYear' => __(':month :year', ['month' => $month->isoFormat('MMMM'), 'year' => $month->year]),
                'monthsAway' => $this->monthsAway,
                'belowMinimum' => $this->belowMinimum,
                'forecastUrl' => route('forecast.index'),
            ],
        );
    }

    /**
     * Delivery gave up: hand the alerts back to the next run.
     */
    public function failed(Throwable $exception): void
    {
        ForecastAlertNotifier::release($this->alertIds);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new RateLimited('emails'))->releaseAfter(1)];
    }

    private function monthDate(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->locale(app()->getLocale());
    }
}
