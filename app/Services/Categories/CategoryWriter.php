<?php

namespace App\Services\Categories;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryColor;
use App\Enums\CategoryDeletionStrategy;
use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Space;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creating, editing and deleting a category, shared by the category MCP tools
 * and `/api/v1/categories`, with the rules both validate with. The inputs
 * arrive validated and with the parent already found in the space, each
 * caller naming the valid ids its own way. Where the category may sit
 * in the tree (no cycle, at most Category::MAX_DEPTH levels, a name unique
 * among its siblings) is checked here, the way the web form checks it, and a
 * refusal is a ValidationException keyed by the field at fault.
 */
class CategoryWriter
{
    private const string NameTakenMessage = 'A category with that name already exists at this level.';

    public function __construct(private CategoryTree $tree) {}

    /**
     * @return array<string, array<mixed>>
     */
    public static function createRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string'],
            'color' => ['required', Rule::enum(CategoryColor::class)],
            'type' => ['required', Rule::enum(CategoryType::class)],
            'cashflow_direction' => ['sometimes', Rule::enum(CategoryCashflowDirection::class)],
        ];
    }

    /**
     * Every field is optional: only what is sent changes.
     *
     * @return array<string, array<mixed>>
     */
    public static function updateRules(): array
    {
        return array_map(
            fn (array $rules): array => ['sometimes', ...array_filter($rules, fn (mixed $rule): bool => ! in_array($rule, ['required', 'sometimes'], true))],
            self::createRules(),
        );
    }

    /**
     * A child takes its parent's type and cashflow direction; a root follows
     * its own type.
     *
     * @param  array<string, mixed>  $attributes  `name`, `icon`, `color` and `type`, and optionally `cashflow_direction`
     */
    public function create(User $user, Space $space, array $attributes, ?Category $parent): Category
    {
        if ($parent !== null) {
            $this->assertPlacement($parent, null);
        }

        $type = $parent !== null ? $parent->type : CategoryType::from((string) $attributes['type']);
        $name = (string) $attributes['name'];

        $this->assertNameFree($space, $name, $parent?->id, null);

        $category = new Category([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'name' => $name,
            'icon' => (string) $attributes['icon'],
            'color' => (string) $attributes['color'],
            'type' => $type->value,
            'cashflow_direction' => self::cashflowDirectionFor($type, $parent, $attributes['cashflow_direction'] ?? null)->value,
            'parent_id' => $parent?->id,
        ]);

        return $this->save($category);
    }

    /**
     * Change only the fields in $changes. Moving the category (a `parent_id`
     * key in $changes, null for a root) re-derives its type and cashflow
     * direction from the new parent, and either cascades down its subtree.
     *
     * @param  array<string, mixed>  $changes  any of `name`, `icon`, `color`, `type`, `cashflow_direction` and `parent_id`
     * @param  Category|null  $parent  the new parent when $changes moves the category, ignored otherwise
     */
    public function update(Category $category, Space $space, array $changes, ?Category $parent): Category
    {
        if (array_key_exists('parent_id', $changes)) {
            if ($parent !== null) {
                $this->assertPlacement($parent, $category);
            }
        } else {
            $parent = $this->currentParent($category, $space);
        }

        $type = $parent !== null ? $parent->type : (CategoryType::tryFrom((string) ($changes['type'] ?? '')) ?? $category->type);
        $requestedDirection = filled($changes['cashflow_direction'] ?? null) ? $changes['cashflow_direction'] : $category->cashflow_direction->value;
        $name = (string) ($changes['name'] ?? $category->name);

        $this->assertNameFree($space, $name, $parent?->id, $category->id);

        $category->fill([
            'name' => $name,
            'icon' => $changes['icon'] ?? $category->icon,
            'color' => $changes['color'] ?? $category->color,
            'type' => $type->value,
            'cashflow_direction' => self::cashflowDirectionFor($type, $parent, $requestedDirection)->value,
            'parent_id' => $parent?->id,
        ]);
        $this->save($category);

        $this->tree->syncDescendantTypes($category);

        return $category->refresh();
    }

    /**
     * Delete the category, uncategorizing its transactions. The strategy
     * decides its children: lifted to its parent, made roots, or deleted with
     * it.
     */
    public function delete(Category $category, CategoryDeletionStrategy $strategy): void
    {
        try {
            match ($strategy) {
                CategoryDeletionStrategy::Cascade => $this->tree->deleteSubtree($category),
                CategoryDeletionStrategy::Promote => $this->tree->detachChildrenAndDelete($category, null),
                CategoryDeletionStrategy::Reparent => $this->tree->detachChildrenAndDelete($category, $category->parent_id),
            };
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'strategy' => 'A category with the same name already exists at the destination level. Rename it first.',
            ]);
        }
    }

    /**
     * The cashflow direction the web form would compute: a child inherits its
     * parent's direction; a root follows its type (savings/investment always
     * count as an outflow, transfers keep the requested direction, everything
     * else is hidden).
     */
    private static function cashflowDirectionFor(CategoryType $type, ?Category $parent, mixed $requested): CategoryCashflowDirection
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

    private function currentParent(Category $category, Space $space): ?Category
    {
        if ($category->parent_id === null) {
            return null;
        }

        return Category::query()->forSpace($space)->whereKey($category->parent_id)->first();
    }

    /**
     * $moving is the category being edited (null on create), so its own
     * subtree counts towards the depth and cannot become its parent.
     */
    private function assertPlacement(Category $parent, ?Category $moving): void
    {
        if ($moving !== null && $this->tree->wouldCreateCycle($moving, $parent->id)) {
            throw ValidationException::withMessages([
                'parent_id' => 'A category cannot be nested under itself or one of its children.',
            ]);
        }

        $subtreeDepth = $moving !== null ? $this->tree->subtreeDepth($moving) : 1;

        if ($this->tree->depth($parent) + $subtreeDepth > Category::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => 'Categories can only be nested up to '.Category::MAX_DEPTH.' levels deep.',
            ]);
        }
    }

    private function assertNameFree(Space $space, string $name, ?string $parentId, ?string $ignoreId): void
    {
        $taken = Category::query()
            ->forSpace($space)
            ->where('name', $name)
            ->where('parent_id', $parentId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => self::NameTakenMessage]);
        }
    }

    /**
     * The database keeps a name unique per owner and level across all their
     * spaces, so a clash the space-scoped check cannot see is refused the
     * same way instead of failing.
     */
    private function save(Category $category): Category
    {
        try {
            $category->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => self::NameTakenMessage]);
        }

        return $category;
    }
}
