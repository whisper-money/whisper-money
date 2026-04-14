<?php

namespace App\Models;

use App\Notifications\VerifyUserLeadEmailNotification;
use Database\Factories\UserLeadFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class UserLead extends Model implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserLeadFactory> */
    use HasFactory, HasUuids, Notifiable;

    protected $fillable = [
        'email',
        'email_verified_at',
        'position',
        'referral_code',
        'referred_by_id',
        'locale',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserLead $lead): void {
            if ($lead->email_verified_at !== null && empty($lead->referral_code)) {
                do {
                    $code = strtoupper(Str::random(8));
                } while (static::where('referral_code', $code)->exists());

                $lead->referral_code = $code;
            }

            if ($lead->email_verified_at !== null && empty($lead->position)) {
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

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => $this->freshTimestamp(),
        ])->save();
    }

    public function markEmailAsUnverified(): bool
    {
        return $this->forceFill([
            'email_verified_at' => null,
            'position' => null,
            'referral_code' => null,
        ])->save();
    }

    public function preferredLocale(): string
    {
        return $this->locale ?? 'en';
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyUserLeadEmailNotification($this->verification_url));
    }

    public function getVerificationUrlAttribute(): string
    {
        return url()->temporarySignedRoute(
            'user-leads.verify',
            now()->addDay(),
            ['lead' => $this->id, 'hash' => sha1(Str::lower($this->email))],
        );
    }

    public function assignWaitlistSpot(): bool
    {
        if ($this->position === null) {
            $this->position = (int) (static::query()->max('position') ?? 499) + 1;
        }

        if (empty($this->referral_code)) {
            do {
                $code = strtoupper(Str::random(8));
            } while (static::where('referral_code', $code)->exists());

            $this->referral_code = $code;
        }

        return $this->save();
    }
}
