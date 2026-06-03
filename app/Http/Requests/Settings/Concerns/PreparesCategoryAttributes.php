<?php

namespace App\Http\Requests\Settings\Concerns;

use App\Http\Requests\Concerns\ResolvesCategoryCashflowDirection;
use App\Models\Category;
use Illuminate\Contracts\Database\Query\Builder;

trait PreparesCategoryAttributes
{
    use ResolvesCategoryCashflowDirection {
        prepareForValidation as resolveCashflowDirectionFromType;
    }

    /**
     * Resolve and cache the requested parent category (owned by the user).
     */
    private ?Category $resolvedParent = null;

    private bool $parentResolved = false;

    /**
     * Derive type and cashflow direction.
     *
     * Children inherit both from their parent so a whole subtree stays on a
     * single type. Roots keep the existing type-driven cashflow rules.
     */
    protected function prepareForValidation(): void
    {
        $parent = $this->parentCategory();

        if ($parent !== null) {
            $this->merge([
                'type' => $parent->type->value,
                'cashflow_direction' => $parent->cashflow_direction->value,
            ]);

            return;
        }

        $this->resolveCashflowDirectionFromType();
    }

    /**
     * The requested parent category, or null when creating/keeping a root.
     */
    protected function parentCategory(): ?Category
    {
        if ($this->parentResolved) {
            return $this->resolvedParent;
        }

        $this->parentResolved = true;

        $parentId = $this->input('parent_id');

        if (blank($parentId)) {
            return $this->resolvedParent = null;
        }

        return $this->resolvedParent = Category::query()
            ->where('user_id', auth()->id())
            ->whereKey($parentId)
            ->first();
    }

    /**
     * Scope a unique-name check to the user and the category's siblings (same
     * parent), treating a null parent as the root level.
     */
    protected function scopeUniqueToSiblings(Builder $query): Builder
    {
        $query->where('user_id', auth()->id());

        $parentId = $this->input('parent_id');

        return blank($parentId)
            ? $query->whereNull('parent_id')
            : $query->where('parent_id', $parentId);
    }
}
