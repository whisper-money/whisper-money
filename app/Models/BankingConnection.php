<?php

namespace App\Models;

use App\Enums\BankingConnectionStatus;
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
 * @property BankingConnectionStatus $status
 * @property Carbon|null $valid_until
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $bank_transactions_email_cutoff_at
 * @property Carbon|null $rate_limited_until
 * @property int $consecutive_sync_failures
 * @property array<int, mixed>|null $pending_accounts_data
 */
class BankingConnection extends Model
{
    /** @use HasFactory<BankingConnectionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'provider',
        'authorization_id',
        'state_token',
        'session_id',
        'aspsp_name',
        'aspsp_country',
        'aspsp_logo',
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
        'api_token',
        'api_secret',
        'pending_accounts_data',
        'authorization_id',
        'state_token',
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BankingConnectionStatus::class,
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
        return $this->provider === 'indexacapital';
    }

    public function isBinance(): bool
    {
        return $this->provider === 'binance';
    }

    public function isBitpanda(): bool
    {
        return $this->provider === 'bitpanda';
    }

    public function isCoinbase(): bool
    {
        return $this->provider === 'coinbase';
    }

    public function isEnableBanking(): bool
    {
        return $this->provider === 'enablebanking';
    }

    public function isWise(): bool
    {
        return $this->provider === 'wise';
    }

    public function hasPendingAccounts(): bool
    {
        return ! empty($this->pending_accounts_data);
    }

    public function isExpired(): bool
    {
        return $this->status === BankingConnectionStatus::Expired
            || ($this->valid_until && $this->valid_until->isPast());
    }

    public function isRateLimited(): bool
    {
        return $this->rate_limited_until !== null
            && $this->rate_limited_until->isFuture();
    }
}
