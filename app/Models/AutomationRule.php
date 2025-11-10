<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AutomationRule extends Model
{
    /** @use HasFactory<\Database\Factories\AutomationRuleFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'title',
        'priority',
        'rules_json',
        'action_category_id',
        'action_note',
        'action_note_iv',
    ];

    protected function casts(): array
    {
        return [
            'rules_json' => 'array',
            'priority' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'action_category_id');
    }
}
