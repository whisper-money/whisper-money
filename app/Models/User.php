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
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
        ];
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
