<?php

namespace App\Models;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\BankingConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property bool $has_pending_accounts
 * @property bool $can_sync_manually
 * @property Carbon|null $next_sync_attempt_at
 * @property string|null $aspsp_name
 * @property string|null $aspsp_country
 * @property bool|null $aspsp_beta
 * @property BankingProvider $provider
 * @property BankingConnectionStatus $status
 * @property Carbon|null $valid_until
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $bank_transactions_email_cutoff_at
 * @property Carbon|null $rate_limited_until
 * @property int $consecutive_sync_failures
 * @property array<int, array<string, mixed>>|null $pending_accounts_data
 */
class BankingConnection extends Model
{
    /** @use HasFactory<BankingConnectionFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    /**
     * How many days before `valid_until` the consent counts as expiring soon:
     * the window in which we warn the user by email and offer an early renewal,
     * so a consent never lapses into days of broken syncing.
     */
    public const EXPIRY_WARNING_DAYS = 7;

    protected $fillable = [
        'user_id',
        'space_id',
        'provider',
        'authorization_id',
        'state_token',
        'session_id',
        'aspsp_name',
        'aspsp_country',
        'aspsp_logo',
        'aspsp_beta',
        'status',
        'valid_until',
        'last_synced_at',
        'bank_transactions_email_cutoff_at',
        'error_message',
        'rate_limited_until',
        'consecutive_sync_failures',
        'pending_accounts_data',
        'api_token',
        'api_secret',
    ];

    protected $hidden = [
        'space_id',
        'api_token',
        'api_secret',
        'pending_accounts_data',
        'authorization_id',
        'state_token',
        'session_id',
    ];

    /**
     * How this connection identifies itself in a log line.
     *
     * Which bank it is decides whether a wave of failures is one connector
     * misbehaving or the provider as a whole, and that is the first question
     * every sync investigation starts with.
     *
     * @return array<string, string|null>
     */
    public function logContext(): array
    {
        return [
            'connection_id' => $this->id,
            'aspsp_name' => $this->aspsp_name,
            'aspsp_country' => $this->aspsp_country,
        ];
    }

    protected function casts(): array
    {
        return [
            'provider' => BankingProvider::class,
            'status' => BankingConnectionStatus::class,
            'aspsp_beta' => 'boolean',
            'valid_until' => 'datetime',
            'last_synced_at' => 'datetime',
            'bank_transactions_email_cutoff_at' => 'datetime',
            'rate_limited_until' => 'datetime',
            'pending_accounts_data' => 'array',
            'api_token' => 'encrypted',
            'api_secret' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<BankingSyncLog, $this> */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(BankingSyncLog::class);
    }

    /** @return HasOne<BankingSyncLog, $this> */
    public function latestSyncLog(): HasOne
    {
        return $this->hasOne(BankingSyncLog::class)->latestOfMany();
    }

    public function isActive(): bool
    {
        return $this->status === BankingConnectionStatus::Active;
    }

    public function isIndexaCapital(): bool
    {
        return $this->provider === BankingProvider::IndexaCapital;
    }

    public function isBinance(): bool
    {
        return $this->provider === BankingProvider::Binance;
    }

    public function isBitpanda(): bool
    {
        return $this->provider === BankingProvider::Bitpanda;
    }

    public function isCoinbase(): bool
    {
        return $this->provider === BankingProvider::Coinbase;
    }

    public function isEnableBanking(): bool
    {
        return $this->provider === BankingProvider::EnableBanking;
    }

    public function isInteractiveBrokers(): bool
    {
        return $this->provider === BankingProvider::InteractiveBrokers;
    }

    public function usesApiKey(): bool
    {
        return $this->provider->usesApiKey();
    }

    public function isWise(): bool
    {
        return $this->provider === BankingProvider::Wise;
    }

    public function hasPendingAccounts(): bool
    {
        return ! empty($this->pending_accounts_data);
    }

    /**
     * Pending accounts the user can actually map. Providers sometimes report accounts
     * without a uid (EnableBanking does it for some French cards); those can never be
     * synced, so offering them for mapping only produces a validation error.
     *
     * @return array<int, array<string, mixed>>
     */
    public function mappablePendingAccounts(): array
    {
        return array_values(array_filter(
            $this->pending_accounts_data ?? [],
            fn (array $account): bool => ! empty($account['uid']),
        ));
    }

    /**
     * Names of the pending accounts left out of the mapping screen, so the user is told
     * why an account they can see in their bank never shows up here.
     *
     * @return array<int, string>
     */
    public function unmappablePendingAccountNames(): array
    {
        return array_values(array_map(
            fn (array $account): string => $account['name'] ?? $account['account_id']['iban'] ?? __('Bank Account'),
            array_filter(
                $this->pending_accounts_data ?? [],
                fn (array $account): bool => empty($account['uid']),
            ),
        ));
    }

    public function isExpired(): bool
    {
        return $this->status === BankingConnectionStatus::Expired
            || ($this->valid_until && $this->valid_until->isPast());
    }

    /**
     * Whether the consent is close enough to running out that the user should
     * renew it now, before syncing breaks. Only EnableBanking connections carry
     * a `valid_until`, so nothing else is ever expiring soon.
     */
    public function isExpiringSoon(): bool
    {
        return $this->valid_until !== null
            && ! $this->isExpired()
            && $this->valid_until->isBefore(now()->addDays(self::EXPIRY_WARNING_DAYS));
    }

    public function isRateLimited(): bool
    {
        return $this->rate_limited_until !== null
            && $this->rate_limited_until->isFuture();
    }
}
