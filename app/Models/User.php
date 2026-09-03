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
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Pennant\Concerns\HasFeatures;
use Laravel\Sanctum\HasApiTokens;
use Stripe\Subscription as StripeSubscription;

/**
 * @property ?Carbon $last_logged_in_at
 * @property ?Carbon $last_active_at
 * @property ?Carbon $transactions_last_visited_at
 * @property ?Carbon $ai_consent_prompt_dismissed_at
 * @property ?Carbon $onboarded_at
 * @property ?string $price_arm
 * @property ?string $signup_plan
 */
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasApiTokens, HasFactory, HasFeatures, HasUuids, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

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
        'price_arm',
        'signup_plan',
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
        'price_arm',
        'signup_plan',
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

    /**
     * Whether the paywall may offer the way down to the free plan, which
     * disconnects the user's banks and revokes their AI consent.
     *
     * Held back for the first few hours after onboarding (see
     * `subscriptions.free_plan_escape_delay_hours`), so a user who has just
     * finished connecting a bank gets to choose a plan before being invited to
     * throw that away. Without an `onboarded_at` there is no window to be past
     * — a half-onboarded user reaching the paywall is not offered the door.
     */
    public function canEscapeToFreePlan(): bool
    {
        if ($this->onboarded_at === null) {
            return false;
        }

        return $this->onboarded_at
            ->addHours(config('subscriptions.free_plan_escape_delay_hours'))
            ->isPast();
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
        if ($this->resolvedActiveSpace !== null) {
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

    /** @return HasMany<SavingsGoal, $this> */
    public function savingsGoals(): HasMany
    {
        return $this->hasMany(SavingsGoal::class);
    }

    /** @return HasMany<MonthlySummary, $this> */
    public function monthlySummaries(): HasMany
    {
        return $this->hasMany(MonthlySummary::class);
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
     * Demo, press and end-to-end fixture accounts are seeded with a made-up
     * Stripe subscription, so every path that would hand that id back to Stripe
     * has to bail out first or the call 404s.
     */
    public function hasSeededSubscription(): bool
    {
        $stripeId = (string) $this->subscription('default')?->stripe_id;

        return Str::startsWith($stripeId, ['sub_demo_', 'sub_press_', 'sub_e2e_']);
    }

    /**
     * Whether the account must never be handed to Stripe. A seeded subscription
     * carries a made-up `stripe_id`, and the shared accounts are checked by
     * e-mail as well so the gap before their first seed is covered too — without
     * it, a billing-portal visit would create a real Stripe customer for an
     * account whose credentials are public.
     */
    public function cannotUseStripe(): bool
    {
        return $this->hasSeededSubscription() || $this->isDemoAccount() || $this->isPressAccount();
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
     * Stripe statuses that leave an invoice Stripe can still put on the card.
     *
     * Deliberately an allowlist: a status we do not know about must not block account
     * deletion, or an unfamiliar one traps the user with nothing to cancel. Cashier's
     * `valid()` cannot stand in for this — it reports `unpaid` and `incomplete` as
     * inactive while Stripe keeps their invoices open. (`past_due` is absent from that
     * list only because {@see Cashier::keepPastDueSubscriptionsActive()}
     * is enabled in AppServiceProvider, so don't reach for `valid()` here again.)
     */
    private const COLLECTABLE_STRIPE_STATUSES = [
        StripeSubscription::STATUS_ACTIVE,
        StripeSubscription::STATUS_TRIALING,
        StripeSubscription::STATUS_PAST_DUE,
        StripeSubscription::STATUS_UNPAID,
        StripeSubscription::STATUS_INCOMPLETE,
    ];

    /**
     * The subscription Stripe can still collect on, whatever its payment state.
     * A cancelled one (`ends_at` set, in grace period or over) issues no further
     * invoices, so it does not count and does not stand in the way of deletion.
     */
    public function collectableSubscription(): ?Subscription
    {
        $subscription = $this->subscription('default');

        if ($subscription === null || $subscription->canceled()) {
            return null;
        }

        return in_array($subscription->stripe_status, self::COLLECTABLE_STRIPE_STATUSES, true)
            ? $subscription
            : null;
    }

    /**
     * Whether the user is still being billed: on a trial or holding a subscription
     * Stripe can still collect on. Such users must cancel before deleting their account.
     */
    public function hasActiveSubscriptionOrTrial(): bool
    {
        if (! config('subscriptions.enabled')) {
            return false;
        }

        if ($this->onGenericTrial()) {
            return true;
        }

        return $this->collectableSubscription() !== null;
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

    /**
     * The press account: a second shared login, handed to journalists so they
     * can drive the AI Connector on seeded Spanish data. Unlike the demo it is
     * allowed to use the MCP, which is the whole point of it.
     */
    public function isPressAccount(): bool
    {
        return $this->email === config('app.press.email');
    }

    /**
     * Whether the demo account's restrictions apply. Local is exempt so the
     * account stays usable in development, matching BlockSharedAccountActions.
     */
    public function isRestrictedDemoAccount(): bool
    {
        return $this->isDemoAccount() && ! app()->environment('local');
    }

    /**
     * Whether the user is one of the accounts whose credentials are public and
     * whose data everyone holding them shares. Nothing destructive and nothing
     * that could hijack the login is allowed on those, and no real bank may be
     * connected to one. Local is exempt so both stay usable in development.
     */
    public function isRestrictedSharedAccount(): bool
    {
        return ($this->isDemoAccount() || $this->isPressAccount()) && ! app()->environment('local');
    }

    /**
     * The e-mail addresses of the shared accounts, whatever the environment.
     * Their traffic is demo traffic, so metrics and automated emails leave them
     * out (see `excludingSharedAccounts`).
     *
     * @return list<string>
     */
    public static function sharedAccountEmails(): array
    {
        return array_values(array_filter([
            (string) config('app.demo.email'),
            (string) config('app.press.email'),
        ]));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeExcludingSharedAccounts(Builder $query): Builder
    {
        return $query->whereNotIn($query->qualifyColumn('email'), self::sharedAccountEmails());
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

    public function wantsInactiveNoBankEmail(): bool
    {
        return $this->setting->notify_on_inactive_no_bank ?? true;
    }

    /**
     * The monthly report and the reminder that precedes it share one switch:
     * nobody wants the nudge without the report. Opt-out, because it is a report
     * about the user's own data rather than a campaign.
     */
    public function wantsMonthlySummaryEmail(): bool
    {
        return $this->setting->notify_monthly_summary ?? true;
    }

    /**
     * Whether a nudge email reached this user recently enough that another one
     * would read as pestering. `$except` leaves one type out, so an email never
     * silences itself — its own cadence is governed by its own dedupe key.
     *
     * The manual-only user is the audience of almost every nudge we have, so
     * without this the monthly reminder and `inactive_no_bank` would land in the
     * same week saying the same thing. Operational mail (banks, billing,
     * verification) is not in {@see DripEmailType::nudges()} and ignores this.
     */
    public function hasReceivedNudgeSince(Carbon $since, ?DripEmailType $except = null): bool
    {
        $types = array_column(DripEmailType::nudges(), 'value');

        return $this->mailLogs()
            ->whereIn('email_type', array_diff($types, [$except?->value]))
            ->where('sent_at', '>=', $since)
            ->exists();
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
