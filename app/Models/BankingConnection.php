<?php

namespace App\Models;

use App\Enums\BankingConnectionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankingConnection extends Model
{
    /** @use HasFactory<\Database\Factories\BankingConnectionFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'provider',
        'authorization_id',
        'session_id',
        'aspsp_name',
        'aspsp_country',
        'aspsp_logo',
        'status',
        'valid_until',
        'last_synced_at',
        'error_message',
        'pending_accounts_data',
        'api_token',
        'api_secret',
    ];

    protected function casts(): array
    {
        return [
            'status' => BankingConnectionStatus::class,
            'valid_until' => 'datetime',
            'last_synced_at' => 'datetime',
            'pending_accounts_data' => 'array',
            'api_token' => 'encrypted',
            'api_secret' => 'encrypted',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
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

    public function isEnableBanking(): bool
    {
        return $this->provider === 'enablebanking';
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
}
