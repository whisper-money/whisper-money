<?php

namespace App\Models;

use App\Enums\LabelSource;
use App\Models\Concerns\BelongsToSpace;
use Database\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property LabelSource $source
 * @property Carbon|null $archived_at
 */
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'name',
        'color',
        'source',
        'user_id',
        'space_id',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => LabelSource::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * An archived label stays on the transactions that already carry it and
     * keeps working in filters and reports, but cannot be assigned anew.
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<Label>  $query
     * @return Builder<Label>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<Label>  $query
     * @return Builder<Label>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * Only labels the user manages directly: the ones they made, and the
     * "Estimate" label the onboarding made for them. Excludes labels that back
     * a savings goal — those are managed through the goal, not the label
     * settings screen.
     *
     * @param  Builder<Label>  $query
     * @return Builder<Label>
     */
    public function scopeUserManaged(Builder $query): Builder
    {
        return $query->whereIn('source', [LabelSource::User, LabelSource::Estimate]);
    }

    /**
     * Hide the pivot from serialization so a Label looks identical whether it
     * is loaded standalone or through a belongsToMany relation.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pivot',
        'space_id',
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
