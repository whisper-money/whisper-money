<?php

namespace App\Models;

use App\Enums\CancellationOffer;
use App\Enums\CancellationOutcome;
use App\Enums\CancellationReason;
use Database\Factories\CancellationSurveyResponseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the in-app cancel flow, from the reason the user gave to how it
 * ended. The free text in `details` stays here: it is never sent to analytics.
 *
 * @property string $stripe_subscription_id
 * @property CancellationReason $reason
 * @property ?string $details
 * @property ?CancellationOffer $offer_shown
 * @property ?CancellationOffer $offer_accepted
 * @property ?CancellationOutcome $outcome
 * @property bool $on_trial
 */
class CancellationSurveyResponse extends Model
{
    /** @use HasFactory<CancellationSurveyResponseFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'stripe_subscription_id',
        'reason',
        'details',
        'offer_shown',
        'offer_accepted',
        'outcome',
        'on_trial',
    ];

    protected function casts(): array
    {
        return [
            'reason' => CancellationReason::class,
            'offer_shown' => CancellationOffer::class,
            'offer_accepted' => CancellationOffer::class,
            'outcome' => CancellationOutcome::class,
            'on_trial' => 'boolean',
        ];
    }

    /**
     * Whether the flow is still open: nothing was accepted or cancelled yet.
     */
    public function isPending(): bool
    {
        return $this->outcome === null;
    }

    public function markSaved(CancellationOffer $offer): void
    {
        $this->update(['offer_accepted' => $offer, 'outcome' => CancellationOutcome::Saved]);
    }

    public function markCanceled(): void
    {
        $this->update(['outcome' => CancellationOutcome::Canceled]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
