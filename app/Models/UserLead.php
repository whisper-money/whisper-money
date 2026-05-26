<?php

namespace App\Models;

use App\Enums\LeadCohort;
use App\Notifications\VerifyUserLeadEmailNotification;
use Carbon\CarbonInterface;
use Database\Factories\UserLeadFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @property string $email
 * @property ?CarbonInterface $invitation_sent_at
 * @property ?CarbonInterface $re_invitation_sent_at
 * @property int $re_invitation_count
 * @property ?LeadCohort $cohort
 */
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
        'cohort',
        'promo_code_monthly',
        'promo_code_yearly',
        'invitation_sent_at',
        're_invitation_sent_at',
        're_invitation_count',
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
            'invitation_sent_at' => 'datetime',
            're_invitation_sent_at' => 'datetime',
            're_invitation_count' => 'integer',
            'cohort' => LeadCohort::class,
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
     * The user account created from this lead email, if any.
     *
     * @return HasOne<User, $this>
     */
    public function signedUpUser(): HasOne
    {
        return $this->hasOne(User::class, 'email', 'email')->withTrashed();
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

    public function routeNotificationForMail(?Notification $notification = null): ?string
    {
        $email = trim((string) $this->email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('Skipping mail notification: invalid recipient email', [
                'user_lead_id' => $this->getKey(),
                'notification' => $notification ? $notification::class : null,
            ]);

            return null;
        }

        return $email;
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
