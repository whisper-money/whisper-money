<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Space;
use App\Services\CategoryTree;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * Category create/update helpers shared by the category write tools. Mirrors
 * the web form's parent resolution (ownership, depth, cycle) and its
 * type-driven cashflow derivation, without depending on `auth()` (which is not
 * the active guard for MCP requests).
 */
trait ResolvesCategoryWrites
{
    /**
     * Resolve the requested parent category within the space, enforcing the
     * same rules as the web form. Returns null when creating/keeping a root.
     * $moving is the category being edited (null on create) so its own subtree
     * depth and cycle constraints can be checked.
     */
    protected function resolveParentCategory(Request $request, Space $space, ?Category $moving): ?Category
    {
        if (! $request->filled('parent_id')) {
            return null;
        }

        $parentId = $request->string('parent_id')->toString();

        $parent = Category::query()->forSpace($space)->whereKey($parentId)->first();

        if ($parent === null) {
            throw ValidationException::withMessages([
                'parent_id' => "No category with id {$parentId} in space {$space->id}. Call list_categories to see valid ids.",
            ]);
        }

        $tree = new CategoryTree;

        if ($moving !== null && $tree->wouldCreateCycle($moving, $parent->id)) {
            throw ValidationException::withMessages([
                'parent_id' => 'A category cannot be nested under itself or one of its children.',
            ]);
        }

        $subtreeDepth = $moving !== null ? $tree->subtreeDepth($moving) : 1;

        if ($tree->depth($parent) + $subtreeDepth > Category::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'Categories can only be nested up to '.Category::MAX_DEPTH.' levels deep.',
            ]);
        }

        return $parent;
    }

    /**
     * Derive the cashflow direction the web form would compute: a child inherits
     * its parent's direction; a root follows its type (savings/investment always
     * count as an outflow, transfers keep the requested direction, everything
     * else is hidden).
     */
    protected function cashflowDirectionFor(CategoryType $type, ?Category $parent, ?string $requested): CategoryCashflowDirection
    {
        if ($parent !== null) {
            return $parent->cashflow_direction;
        }

        return match ($type) {
            CategoryType::Savings, CategoryType::Investment => CategoryCashflowDirection::Outflow,
            CategoryType::Transfer => CategoryCashflowDirection::tryFrom((string) $requested) ?? CategoryCashflowDirection::Hidden,
            default => CategoryCashflowDirection::Hidden,
        };
    }

    /**
     * Whether a sibling category (same parent) with the given name already
     * exists in the space, optionally ignoring one category by id (for edits).
     */
    protected function categoryNameTaken(Space $space, string $name, ?string $parentId, ?string $ignoreId = null): bool
    {
        return Category::query()
            ->forSpace($space)
            ->where('name', $name)
            ->where('parent_id', $parentId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
    }
}
