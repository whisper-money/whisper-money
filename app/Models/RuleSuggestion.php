<?php

namespace App\Models;

use App\Enums\RuleSuggestionStatus;
use Database\Factories\RuleSuggestionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleSuggestion extends Model
{
    /** @use HasFactory<RuleSuggestionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'suggestion_run_id',
        'group_key',
        'match_field',
        'match_operator',
        'match_token',
        'proposed_category_id',
        'new_category_name',
        'new_category_parent_id',
        'new_category_direction',
        'confidence',
        'group_size',
        'sample_descriptions',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'group_size' => 'integer',
            'sample_descriptions' => 'array',
            'status' => RuleSuggestionStatus::class,
        ];
    }

    /** @return BelongsTo<SuggestionRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SuggestionRun::class, 'suggestion_run_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function proposedCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'proposed_category_id');
    }

    /**
     * Whether this suggestion proposes creating a brand-new category.
     */
    public function proposesNewCategory(): bool
    {
        return $this->proposed_category_id === null && $this->new_category_name !== null;
    }

    /**
     * Stable key identifying the category this suggestion targets, so suggestions
     * heading to the same category can be grouped into a single OR rule.
     */
    public function categoryGroupKey(): string
    {
        if ($this->proposed_category_id !== null) {
            return 'cat:'.$this->proposed_category_id;
        }

        return 'new:'.((string) $this->new_category_direction).':'.mb_strtolower(trim((string) $this->new_category_name));
    }
}
