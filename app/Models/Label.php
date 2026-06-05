<?php

namespace App\Models;

use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'color',
        'user_id',
    ];

    /**
     * Hide the pivot from serialization so a Label looks identical whether it
     * is loaded standalone or through a belongsToMany relation.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pivot',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class)
            ->using(LabelTransaction::class)
            ->withTimestamps();
    }

    public function automationRules(): BelongsToMany
    {
        return $this->belongsToMany(AutomationRule::class, 'automation_rule_labels')
            ->using(AutomationRuleLabel::class);
    }
}
