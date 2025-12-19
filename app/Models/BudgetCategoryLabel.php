<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class BudgetCategoryLabel extends Pivot
{
    use HasUuids;

    protected $table = 'budget_category_labels';

    public $incrementing = false;

    protected $keyType = 'string';

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    public function label(): BelongsTo
    {
        return $this->belongsTo(Label::class);
    }
}

