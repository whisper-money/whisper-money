<?php

namespace App\Models;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Concerns\BelongsToSpace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property RolloverType $rollover_type
 * @property BudgetPeriodType $period_type
 */
class Budget extends Model
{
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'name',
        'period_type',
        'period_start_day',
        'rollover_type',
        'is_catch_all',
    ];

    /** @var list<string> */
    protected $hidden = [
        'period_duration',
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => BudgetPeriodType::class,
            'rollover_type' => RolloverType::class,
            'period_start_day' => 'integer',
            'is_catch_all' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
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
