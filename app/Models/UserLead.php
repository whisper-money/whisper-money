<?php

namespace App\Models;

use Database\Factories\UserLeadFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class UserLead extends Model
{
    /** @use HasFactory<UserLeadFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'email',
        'position',
        'referral_code',
        'referred_by_id',
        'locale',
    ];

    protected static function booted(): void
    {
        static::creating(function (UserLead $lead): void {
            if (empty($lead->referral_code)) {
                do {
                    $code = strtoupper(Str::random(8));
                } while (static::where('referral_code', $code)->exists());

                $lead->referral_code = $code;
            }

            if (empty($lead->position)) {
                $maxPosition = static::max('position') ?? 499;
                $lead->position = (int) $maxPosition + 1;
            }

            if (empty($lead->locale)) {
                $lead->locale = app()->getLocale();
            }
        });
    }

    /**
     * The lead who referred this person.
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(UserLead::class, 'referred_by_id');
    }

    /**
     * The leads this person has referred.
     *
     * @return HasMany<UserLead, $this>
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(UserLead::class, 'referred_by_id');
    }

    /**
     * The shareable referral URL for this lead.
     */
    public function getReferralUrlAttribute(): string
    {
        return url('/').'?ref='.$this->referral_code;
    }
}
