<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetPeriod extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'budget_id',
        'start_date',
        'end_date',
        'carried_over_amount',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'carried_over_amount' => 'integer',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetPeriodAllocation::class);
    }

    public function isActive(): bool
    {
        $now = now();

        return $this->start_date <= $now && $this->end_date >= $now;
    }

    public function calculateSpent(): int
    {
        return $this->allocations->sum(function ($allocation) {
            return $allocation->calculateSpent();
        });
    }

    public function calculateRemaining(): int
    {
        return $this->allocations->sum(function ($allocation) {
            return $allocation->calculateRemaining();
        });
    }
}

