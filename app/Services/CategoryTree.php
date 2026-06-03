<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Transaction;

class CategoryTree
{
    /**
     * Expand a set of category ids to also include every descendant id.
     *
     * Used when filtering transactions: selecting a parent must also match
     * transactions assigned to any of its children. Bounded by MAX_DEPTH, so
     * this resolves in at most a couple of cheap queries.
     *
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    public function expand(string $userId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if ($ids === []) {
            return [];
        }

        $collected = $ids;
        $frontier = $ids;

        for ($level = 1; $level < Category::MAX_DEPTH; $level++) {
            $childIds = Category::query()
                ->where('user_id', $userId)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            $childIds = array_values(array_diff($childIds, $collected));

            if ($childIds === []) {
                break;
            }

            $collected = array_merge($collected, $childIds);
            $frontier = $childIds;
        }

        return $collected;
    }

    /**
     * Collect the descendant ids of a single category (excluding itself).
     *
     * @return array<int, string>
     */
    public function descendantIds(Category $category): array
    {
        return array_values(array_diff(
            $this->expand($category->user_id, [$category->id]),
            [$category->id],
        ));
    }

    /**
     * A category's id together with every ancestor id, walking up to the root.
     *
     * @return array<int, string>
     */
    public function ancestorAndSelfIds(string $userId, string $categoryId): array
    {
        $ids = [$categoryId];
        $parentId = Category::query()
            ->where('user_id', $userId)
            ->whereKey($categoryId)
            ->value('parent_id');

        $guard = 0;

        while ($parentId !== null && $guard++ < Category::MAX_DEPTH) {
            $ids[] = $parentId;
            $parentId = Category::query()
                ->where('user_id', $userId)
                ->whereKey($parentId)
                ->value('parent_id');
        }

        return $ids;
    }

    /**
     * The depth of a category, where a root category is level 1.
     */
    public function depth(Category $category): int
    {
        $depth = 1;
        $parentId = $category->parent_id;

        while ($parentId !== null && $depth <= Category::MAX_DEPTH) {
            $parentId = Category::query()
                ->where('user_id', $category->user_id)
                ->where('id', $parentId)
                ->value('parent_id');

            $depth++;
        }

        return $depth;
    }

    /**
     * The number of levels contained in a category's subtree, including itself
     * (a leaf is 1).
     */
    public function subtreeDepth(Category $category): int
    {
        $depth = 1;
        $frontier = [$category->id];

        for ($level = 1; $level < Category::MAX_DEPTH; $level++) {
            $childIds = Category::query()
                ->where('user_id', $category->user_id)
                ->whereIn('parent_id', $frontier)
                ->pluck('id')
                ->all();

            if ($childIds === []) {
                break;
            }

            $depth++;
            $frontier = $childIds;
        }

        return $depth;
    }

    /**
     * Map every category id of a user to its top-level ancestor id (roots map
     * to themselves). Used to roll child amounts up into their parent.
     *
     * @return array<string, string>
     */
    public function rootAncestorMap(string $userId): array
    {
        $parents = Category::query()
            ->where('user_id', $userId)
            ->pluck('parent_id', 'id')
            ->all();

        $map = [];

        foreach ($parents as $id => $parentId) {
            $rootId = $id;
            $guard = 0;

            while ($parentId !== null && array_key_exists($parentId, $parents) && $guard++ < Category::MAX_DEPTH) {
                $rootId = $parentId;
                $parentId = $parents[$parentId];
            }

            $map[$id] = $rootId;
        }

        return $map;
    }

    /**
     * Whether assigning $parentId as the parent of $category would create a
     * cycle (the parent is the category itself or one of its descendants).
     */
    public function wouldCreateCycle(Category $category, string $parentId): bool
    {
        if ($parentId === $category->id) {
            return true;
        }

        return in_array($parentId, $this->descendantIds($category), true);
    }

    /**
     * Cascade a category's type and cashflow direction down to every
     * descendant, keeping the whole subtree on a single type.
     */
    public function syncDescendantTypes(Category $category): void
    {
        $descendantIds = $this->descendantIds($category);

        if ($descendantIds === []) {
            return;
        }

        Category::query()
            ->where('user_id', $category->user_id)
            ->whereIn('id', $descendantIds)
            ->update([
                'type' => $category->type,
                'cashflow_direction' => $category->cashflow_direction,
            ]);
    }

    /**
     * Soft-delete a category together with its whole subtree, uncategorizing
     * any transactions that pointed at the removed categories.
     */
    public function deleteSubtree(Category $category): void
    {
        $ids = [$category->id, ...$this->descendantIds($category)];

        Transaction::query()
            ->where('user_id', $category->user_id)
            ->whereIn('category_id', $ids)
            ->update(['category_id' => null]);

        Category::query()
            ->where('user_id', $category->user_id)
            ->whereIn('id', $ids)
            ->delete();
    }
}
