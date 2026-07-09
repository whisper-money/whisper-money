<?php

namespace App\Models;

use App\Enums\DripEmailType;
use App\Enums\PlanFeature;
use App\Notifications\VerifyEmailNotification;
use Carbon\Carbon;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Billable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Pennant\Concerns\HasFeatures;

/**
 * @property ?Carbon $last_logged_in_at
 * @property ?Carbon $last_active_at
 * @property ?Carbon $transactions_last_visited_at
 * @property ?Carbon $ai_consent_prompt_dismissed_at
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, HasFeatures, HasUuids, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'encryption_salt',
        'onboarded_at',
        'paywall_seen_at',
        'currency_code',
        'locale',
        'timezone',
        'current_space_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
        'stripe_id',
        'pm_type',
        'pm_last_four',
        'trial_ends_at',
        'encryption_salt',
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
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'onboarded_at' => 'datetime',
            'paywall_seen_at' => 'datetime',
            'last_logged_in_at' => 'datetime',
            'last_active_at' => 'datetime',
            'transactions_last_visited_at' => 'datetime',
            'ai_consent_prompt_dismissed_at' => 'datetime',
        ];
    }

    /**
     * Memoized active space for the current request lifecycle.
     */
    protected ?Space $resolvedActiveSpace = null;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            $user->provisionPersonalSpace();
        });
    }

    public function isOnboarded(): bool
    {
        return $this->onboarded_at !== null;
    }

    public function hasSeenPaywall(): bool
    {
        return $this->paywall_seen_at !== null;
    }

    /** @return HasOne<UserSetting, $this> */
    public function setting(): HasOne
    {
        return $this->hasOne(UserSetting::class);
    }

    /** @return HasOne<EncryptedMessage, $this> */
    public function encryptedMessage(): HasOne
    {
        return $this->hasOne(EncryptedMessage::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Bank, $this> */
    public function banks(): HasMany
    {
        return $this->hasMany(Bank::class)
            ->where(function (Builder $query) {
                $query->whereNull('user_id')
                    ->orWhere('banks.user_id', $this->id);
            });
    }

    /** @return BelongsTo<Space, $this> */
    public function currentSpace(): BelongsTo
    {
        return $this->belongsTo(Space::class, 'current_space_id');
    }

    /** @return HasMany<Space, $this> */
    public function ownedSpaces(): HasMany
    {
        return $this->hasMany(Space::class, 'owner_id');
    }

    /**
     * The one personal space every user owns (created on registration).
     *
     * @return HasOne<Space, $this>
     */
    public function personalSpace(): HasOne
    {
        return $this->hasOne(Space::class, 'owner_id')->where('personal', true);
    }

    /**
     * Spaces the user was invited into (excludes the ones they own).
     *
     * @return BelongsToMany<Space, $this>
     */
    public function memberSpaces(): BelongsToMany
    {
        return $this->belongsToMany(Space::class, 'space_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Every space the user can access: the ones they own plus the ones they were
     * invited into, ordered with the personal space first.
     *
     * @return Collection<int, Space>
     */
    public function accessibleSpaces(): Collection
    {
        return Space::query()
            ->where('owner_id', $this->id)
            ->orWhereHas('members', fn (Builder $query) => $query->whereKey($this->id))
            ->orderByDesc('personal')
            ->orderBy('name')
            ->get();
    }

    /**
     * Idempotently ensure the user has a personal space and points at it.
     */
    public function provisionPersonalSpace(): Space
    {
        $space = $this->ownedSpaces()->firstOrCreate(
            ['personal' => true],
            ['name' => 'Personal'],
        );

        if ($this->current_space_id === null) {
            $this->forceFill(['current_space_id' => $space->id])->saveQuietly();
        }

        return $space;
    }

    /**
     * The space the user is currently working in. Falls back to (and repairs
     * towards) the personal space when the pointer is missing or points at a
     * space the user can no longer access — e.g. after a membership is revoked or
     * a Business subscription lapses.
     */
    public function activeSpace(): Space
    {
        if ($this->resolvedActiveSpace !== null
            && $this->resolvedActiveSpace->id === $this->current_space_id) {
            return $this->resolvedActiveSpace;
        }

        $space = $this->current_space_id !== null
            ? Space::query()->find($this->current_space_id)
            : null;

        if ($space === null || ! $space->hasMember($this)) {
            $space = $this->provisionPersonalSpace();
            $this->forceFill(['current_space_id' => $space->id])->saveQuietly();
        }

        return $this->resolvedActiveSpace = $space;
    }

    /**
     * Whether a paid feature is available while acting in the given space. The
     * space owner's plan governs the space, so a member of a Business space gets
     * its paid features without a plan of their own.
     */
    public function canUseFeatureInSpace(PlanFeature $feature, ?Space $space = null): bool
    {
        if (! $feature->requiresProPlan()) {
            return true;
        }

        $space ??= $this->activeSpace();
        $owner = $space->owner_id === $this->id ? $this : $space->owner;

        return $owner->hasProPlan();
    }

    /**
     * Unique users counted against the owner's subscription: the owner, every
     * member across their spaces, and still-pending invitations. This is the
     * seat count the Business plan caps.
     */
    public function seatsInUse(): int
    {
        $ownedSpaceIds = $this->ownedSpaces()->pluck('id');

        $members = static::query()
            ->whereHas('memberSpaces', fn (Builder $query) => $query->whereIn('spaces.id', $ownedSpaceIds))
            ->get(['id', 'email']);

        $pendingEmails = SpaceInvitation::query()
            ->whereIn('space_id', $ownedSpaceIds)
            ->whereNull('accepted_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('email')
            ->unique()
            ->reject(fn (string $email): bool => $members->pluck('email')->contains($email));

        return 1 + $members->count() + $pendingEmails->count();
    }

    /** @return HasMany<AutomationRule, $this> */
    public function automationRules(): HasMany
    {
        return $this->hasMany(AutomationRule::class);
    }

    /** @return HasMany<AiConsent, $this> */
    public function aiConsents(): HasMany
    {
        return $this->hasMany(AiConsent::class);
    }

    /** @return HasMany<SuggestionRun, $this> */
    public function suggestionRuns(): HasMany
    {
        return $this->hasMany(SuggestionRun::class);
    }

    /** @return HasMany<IntegrationRequest, $this> */
    public function integrationRequests(): HasMany
    {
        return $this->hasMany(IntegrationRequest::class);
    }

    /** @return HasMany<IntegrationRequestVote, $this> */
    public function integrationRequestVotes(): HasMany
    {
        return $this->hasMany(IntegrationRequestVote::class);
    }

    /**
     * Whether the user has an active, current-version AI consent.
     */
    public function hasActiveAiConsent(string $scope = AiConsent::SCOPE_FINANCE): bool
    {
        return $this->aiConsents()->active($scope)->exists();
    }

    /**
     * Record an AI consent for the current consent version (idempotent).
     */
    public function recordAiConsent(string $scope = AiConsent::SCOPE_FINANCE): AiConsent
    {
        return $this->aiConsents()->firstOrCreate(
            [
                'scope' => $scope,
                'version' => (string) config('ai_suggestions.consent_version'),
                'revoked_at' => null,
            ],
            ['accepted_at' => now()],
        );
    }

    /**
     * Revoke any active AI consents for the given scope.
     */
    public function revokeAiConsent(string $scope = AiConsent::SCOPE_FINANCE): void
    {
        $this->aiConsents()->active($scope)->update(['revoked_at' => now()]);
    }

    /**
     * Whether the user has already answered the AI consent prompt (accepted or
     * dismissed it), so the transactions banner should no longer be shown.
     */
    public function hasDismissedAiConsentPrompt(): bool
    {
        return $this->ai_consent_prompt_dismissed_at !== null;
    }

    /**
     * Permanently dismiss the AI consent prompt (idempotent).
     */
    public function dismissAiConsentPrompt(): void
    {
        if ($this->ai_consent_prompt_dismissed_at === null) {
            $this->forceFill(['ai_consent_prompt_dismissed_at' => now()])->save();
        }
    }

    /** @return HasMany<Label, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /** @return HasMany<UserMailLog, $this> */
    public function mailLogs(): HasMany
    {
        return $this->hasMany(UserMailLog::class);
    }

    /** @return HasMany<Budget, $this> */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /** @return HasMany<BankingConnection, $this> */
    public function bankingConnections(): HasMany
    {
        return $this->hasMany(BankingConnection::class);
    }

    public function hasReceivedEmail(DripEmailType $type): bool
    {
        return $this->mailLogs()->where('email_type', $type)->exists();
    }

    public function hasProPlan(): bool
    {
        if (! config('subscriptions.enabled')) {
            return true;
        }

        return $this->subscribed('default');
    }

    /**
     * Whether the user can access the given feature on their current plan.
     */
    public function canUseFeature(PlanFeature $feature): bool
    {
        if (! $feature->requiresProPlan()) {
            return true;
        }

        return $this->hasProPlan();
    }

    public function hasPastDueSubscription(): bool
    {
        if (! config('subscriptions.enabled')) {
            return false;
        }

        $subscription = $this->subscription('default');

        return $subscription !== null
            && $subscription->stripe_status === 'past_due'
            && ! $subscription->ended();
    }

    public function hasCanceledSubscription(): bool
    {
        if (! config('subscriptions.enabled')) {
            return false;
        }

        $subscription = $this->subscription('default');

        return $subscription !== null
            && $subscription->stripe_status === 'canceled'
            && $subscription->ended();
    }

    /**
     * Whether the user is still being billed: on a trial or holding an
     * active subscription that has not been cancelled (grace period excluded,
     * as it will not renew). Such users must cancel before deleting their account.
     */
    public function hasActiveSubscriptionOrTrial(): bool
    {
        if (! config('subscriptions.enabled')) {
            return false;
        }

        if ($this->onGenericTrial()) {
            return true;
        }

        $subscription = $this->subscription('default');

        return $subscription !== null
            && $subscription->valid()
            && ! $subscription->onGracePeriod();
    }

    /**
     * The tax rates that should apply to the customer's subscriptions.
     *
     * @return array<int, string>
     */
    public function taxRates(): array
    {
        return config('subscriptions.tax_rates', []);
    }

    public function isDemoAccount(): bool
    {
        return $this->email === config('app.demo.email');
    }

    public function isAdmin(): bool
    {
        return $this->email === config('mail.admin_email');
    }

    public function preferredLocale(): string
    {
        return $this->locale ?? 'en';
    }

    public function isDeleted(): bool
    {
        return $this->trashed();
    }

    public function markAsDeleted(): void
    {
        if ($this->trashed()) {
            return;
        }

        DB::transaction(function () {
            $this->forceFill([
                'email' => $this->deletedEmail(),
            ])->saveQuietly();

            $this->delete();
        });
    }

    public function canReceiveEmails(): bool
    {
        return ! $this->isDeleted();
    }

    public function wantsBankTransactionsSyncedEmail(): bool
    {
        return $this->setting->notify_on_bank_transactions_synced ?? true;
    }

    public function routeNotificationForMail(?Notification $notification = null): ?string
    {
        if (! $this->canReceiveEmails()) {
            return null;
        }

        $email = trim((string) $this->email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            Log::warning('Skipping mail notification: invalid recipient email', [
                'user_id' => $this->getKey(),
                'notification' => $notification ? $notification::class : null,
            ]);

            return null;
        }

        return $email;
    }

    public function sendEmailVerificationNotification(): void
    {
        if (! $this->canReceiveEmails()) {
            return;
        }

        $this->notify(new VerifyEmailNotification);
    }

    private function deletedEmail(): string
    {
        $timestamp = $this->freshTimestamp()->format('YmdHis');
        $originalEmail = $this->getOriginal('email');
        $candidate = "{$timestamp}_{$originalEmail}";

        if (! static::withTrashed()->where('email', $candidate)->exists()) {
            return $candidate;
        }

        return "{$timestamp}_{$this->getKey()}_{$originalEmail}";
    }
}
