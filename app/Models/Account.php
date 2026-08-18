<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToSpace;
use App\Services\BudgetTransactionService;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property AccountType $type
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'name',
        'name_iv',
        'bank_id',
        'currency_code',
        'type',
        'encrypted',
        'banking_connection_id',
        'external_account_id',
        'iban',
        'linked_at',
        'position',
        'archived',
        'ownership_percentage',
        'ownership_applies_to_balance',
    ];

    /** @var list<string> */
    protected $hidden = [
        'user_id',
        'space_id',
        'bank_id',
        'iban',
        'position',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @var list<string> */
    protected $appends = [
        'linked_loan_account_id',
    ];

    /**
     * Budget amounts are snapshots taken when a transaction is assigned, so a
     * new ownership share only reaches the budgets that already counted this
     * account if something rewrites them. Hooked on the model rather than on
     * the settings controller so every write path is covered.
     */
    protected static function booted(): void
    {
        static::updated(function (Account $account): void {
            if ($account->wasChanged('ownership_percentage')) {
                app(BudgetTransactionService::class)->reweighAccountSnapshots($account);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'encrypted' => 'boolean',
            'linked_at' => 'datetime',
            'position' => 'integer',
            'archived' => 'boolean',
            'ownership_percentage' => 'integer',
            'ownership_applies_to_balance' => 'boolean',
        ];
    }

    /**
     * Archived accounts are out of sight everywhere the user picks or reviews
     * accounts; only the settings list still shows them so they can come back.
     *
     * @param  Builder<Account>  $query
     * @return Builder<Account>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->where('archived', false);
    }

    /**
     * The owner's share of an amount held in this account, in the same minor
     * units. A shared account (say 50% with a partner) only contributes that
     * slice of every transaction to the user's own figures.
     */
    public function shareOfAmount(int $amount): int
    {
        // Defaults to full ownership so a model built without the column (a
        // partial select, a fresh factory instance) reads at face value
        // instead of silently zeroing every amount.
        $percentage = $this->ownership_percentage ?? 100;

        if ($percentage >= 100) {
            return $amount;
        }

        return (int) round($amount * $percentage / 100);
    }

    /**
     * The linked loan account id, surfaced from the real estate detail. Guarded
     * on relationLoaded so serialization never triggers a lazy load, avoiding
     * N+1 queries when accounts are listed without the relation.
     */
    protected function linkedLoanAccountId(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->relationLoaded('realEstateDetail')
            ? $this->realEstateDetail?->linked_loan_account_id
            : null);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<AccountBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    /** @return HasMany<AccountImportConfig, $this> */
    public function importConfigs(): HasMany
    {
        return $this->hasMany(AccountImportConfig::class);
    }

    /** @return BelongsTo<BankingConnection, $this> */
    public function bankingConnection(): BelongsTo
    {
        return $this->belongsTo(BankingConnection::class);
    }

    /** @return HasOne<RealEstateDetail, $this> */
    public function realEstateDetail(): HasOne
    {
        return $this->hasOne(RealEstateDetail::class);
    }

    /** @return HasOne<LoanDetail, $this> */
    public function loanDetail(): HasOne
    {
        return $this->hasOne(LoanDetail::class);
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
