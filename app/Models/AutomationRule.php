<?php

namespace App\Models;

use App\Enums\RuleOrigin;
use App\Models\Concerns\BelongsToSpace;
use Database\Factories\AutomationRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<string, mixed> $rules_json
 * @property RuleOrigin $origin
 */
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'title',
        'priority',
        'origin',
        'rules_json',
        'action_category_id',
        'action_note',
        'action_note_iv',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'rules_json' => 'array',
            'priority' => 'integer',
            'origin' => RuleOrigin::class,
        ];
    }

    /**
     * @param  Builder<AutomationRule>  $query
     * @return Builder<AutomationRule>
     */
    public function scopeOrigin(Builder $query, RuleOrigin $origin): Builder
    {
        return $query->where('origin', $origin);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'action_category_id');
    }

    /** @return BelongsToMany<Label, $this, AutomationRuleLabel, 'pivot'> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class, 'automation_rule_labels')
            ->using(AutomationRuleLabel::class);
    }
}
