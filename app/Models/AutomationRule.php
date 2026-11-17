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
 * Rules written before the store request stopped double-encoding the payload
 * came back out of the array cast as the JSON string it wrapped, so readers
 * have to cope with both shapes — see AutomationRuleService::normalizeRuleJson.
 *
 * @property array<string, mixed>|string $rules_json
 * @property RuleOrigin $origin
 */
class AutomationRule extends Model
{
    /** @use HasFactory<AutomationRuleFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    public const MAX_TITLE_LENGTH = 255;

    protected $fillable = [
        'user_id',
        'space_id',
        'title',
        'priority',
        'origin',
        'rules_json',
        'action_category_id',
        'action_note',
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
     * Generated titles are assembled from bank-supplied merchant names and AI
     * tokens, neither of which the column bounds. Truncating here is the one
     * place that covers every generator, so an oversized title can never abort
     * the action that produced it. User-authored titles never reach this: the
     * form request rejects anything over the same limit with a 422.
     */
    public function setTitleAttribute(?string $value): void
    {
        $this->attributes['title'] = $value === null
            ? null
            : mb_substr($value, 0, self::MAX_TITLE_LENGTH);
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

    /**
     * The ids of the labels the rule applies, archived ones included.
     *
     * @return list<string>
     */
    public function labelIds(): array
    {
        return $this->labels()->pluck('labels.id')->values()->all();
    }

    /**
     * The labels applying the rule adds: its labels minus the archived ones,
     * which it skips while still applying its other actions.
     *
     * @return list<string>
     */
    public function assignableLabelIds(): array
    {
        $labels = $this->labels;

        // Some screens load the labels with only their id; archived_at decides
        // here, so they are reloaded once, in full, and kept on the rule.
        if ($labels->contains(fn (Label $label): bool => ! array_key_exists('archived_at', $label->getAttributes()))) {
            $labels = $this->labels()->get();
            $this->setRelation('labels', $labels);
        }

        return $labels->reject(fn (Label $label): bool => $label->isArchived())->pluck('id')->values()->all();
    }
}
