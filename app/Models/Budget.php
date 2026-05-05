<?php

namespace App\Models;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property RolloverType $rollover_type
 * @property BudgetPeriodType $period_type
 */
class Budget extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'period_type',
        'period_start_day',
        'category_id',
        'label_id',
        'rollover_type',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => BudgetPeriodType::class,
            'rollover_type' => RolloverType::class,
            'period_start_day' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Label, $this> */
    public function label(): BelongsTo
    {
        return $this->belongsTo(Label::class);
    }

    /** @return HasMany<BudgetPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    public function getCurrentPeriod(): ?BudgetPeriod
    {
        return $this->periods()
            ->where('start_date', '<=', today())
            ->where('end_date', '>=', today())
            ->first();
    }
}
