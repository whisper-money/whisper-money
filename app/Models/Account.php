<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    /** @use HasFactory<\Database\Factories\AccountFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'name_iv',
        'bank_id',
        'currency_code',
        'type',
        'encrypted',
        'banking_connection_id',
        'external_account_id',
        'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'encrypted' => 'boolean',
            'linked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    public function bankingConnection(): BelongsTo
    {
        return $this->belongsTo(BankingConnection::class);
    }

    public function isConnected(): bool
    {
        return $this->banking_connection_id !== null;
    }

    public function isLinked(): bool
    {
        return $this->linked_at !== null;
    }
}
