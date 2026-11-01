<?php

namespace App\Models;

use App\Enums\FreePlanSurveyReason;
use App\Enums\PaywallGate;
use Database\Factories\FreePlanSurveyResponseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to the survey the paywall shows when a user stays on the free
 * plan. The free text in `details` stays here: it is never sent to analytics.
 *
 * @property FreePlanSurveyReason $reason
 * @property ?string $details
 * @property PaywallGate $gate
 */
class FreePlanSurveyResponse extends Model
{
    /** @use HasFactory<FreePlanSurveyResponseFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'reason',
        'details',
        'gate',
    ];

    protected function casts(): array
    {
        return [
            'reason' => FreePlanSurveyReason::class,
            'gate' => PaywallGate::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
