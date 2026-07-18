<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\BelongsToSpace;
use Database\Factories\AccountFactory;
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
        'hidden_on_dashboard',
    ];

    /** @var list<string> */
    protected $hidden = [
        'user_id',
        'space_id',
        'bank_id',
        'iban',
        'position',
        'hidden_on_dashboard',
        'created_at',
        'updated_at',
        'deleted_at',
    ];

    /** @var list<string> */
    protected $appends = [
        'linked_loan_account_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'encrypted' => 'boolean',
            'linked_at' => 'datetime',
            'position' => 'integer',
            'hidden_on_dashboard' => 'boolean',
        ];
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
