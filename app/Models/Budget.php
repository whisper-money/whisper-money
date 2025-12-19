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

class Budget extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'period_type',
        'period_duration',
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
            'period_duration' => 'integer',
            'period_start_day' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(Label::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    public function getCurrentPeriod(): ?BudgetPeriod
    {
        return $this->periods()
            ->where('start_date', '<=', now())
            ->where('end_date', '>=', now())
            ->first();
    }
}
