<?php

namespace App\Models;

use App\Enums\RolloverType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetCategory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'budget_id',
        'category_id',
        'rollover_type',
    ];

    protected function casts(): array
    {
        return [
            'rollover_type' => RolloverType::class,
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'budget_category_labels');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetPeriodAllocation::class);
    }
}

