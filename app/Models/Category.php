<?php

namespace App\Models;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $name
 * @property string $icon
 * @property string $color
 * @property CategoryType $type
 * @property CategoryCashflowDirection $cashflow_direction
 * @property string $user_id
 * @property string|null $parent_id
 */
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * Maximum allowed nesting depth (a root counts as level 1).
     */
    public const int MAX_DEPTH = 3;

    protected $fillable = [
        'name',
        'icon',
        'color',
        'type',
        'cashflow_direction',
        'user_id',
        'parent_id',
    ];

    /** @var list<string> */
    protected $hidden = [
        'user_id',
        'created_at',
        'updated_at',
        'deleted_at',
        'active_unique_marker',
        'parent_unique_marker',
        'pivot',
    ];

    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'cashflow_direction' => CategoryCashflowDirection::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    /** @return HasMany<Category, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Recursively eager-load the whole subtree (bounded by MAX_DEPTH).
     *
     * @return HasMany<Category, $this>
     */
    public function descendants(): HasMany
    {
        return $this->children()->with('descendants');
    }

    /**
     * Whether this category sits at the top of the tree.
     */
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Scope for fetching categories to send to the frontend: the full, ordered
     * Category shape used by every category selector. The serialized shape is
     * controlled by $hidden so every consumer receives the same object.
     *
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeForDisplay(Builder $query): Builder
    {
        return $query->orderBy('name');
    }
}
