<?php

namespace App\Models;

use App\Enums\PropertyType;
use Database\Factories\RealEstateDetailFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PropertyType $property_type
 * @property int|null $purchase_price
 * @property string|null $revaluation_percentage
 */
class RealEstateDetail extends Model
{
    /** @use HasFactory<RealEstateDetailFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'account_id',
        'linked_loan_account_id',
        'property_type',
        'address',
        'purchase_price',
        'purchase_date',
        'area_value',
        'area_unit',
        'notes',
        'revaluation_percentage',
    ];

    protected function casts(): array
    {
        return [
            'property_type' => PropertyType::class,
            'purchase_price' => 'integer',
            'purchase_date' => 'date',
            'area_value' => 'decimal:2',
            'revaluation_percentage' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function linkedLoanAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'linked_loan_account_id');
    }
}
