<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The closed, indexed list of a user's LEAF categories handed to the
 * categorization agent. Categories are referenced by a stable integer index
 * rather than their UUID so the model cannot hallucinate an identifier; the
 * catalog maps a returned index back to a real category id.
 */
class CategoryCatalog
{
    /**
     * @param  array<int, string>  $idByIndex  index => category id
     * @param  list<array{index: int, path: string, type: string, direction: string}>  $options
     */
    private function __construct(
        private readonly array $idByIndex,
        private readonly array $options,
    ) {}

    public static function forUser(User $user): self
    {
        $categories = Category::query()
            ->where('user_id', $user->id)
            ->get();

        $byId = $categories->keyBy('id');
        $parentIds = $categories->pluck('parent_id')->filter()->unique()->flip();

        $idByIndex = [];
        $options = [];
        $index = 0;

        foreach ($categories as $category) {
            if ($parentIds->has($category->id)) {
                continue;
            }

            $idByIndex[$index] = $category->id;
            $options[] = [
                'index' => $index,
                'path' => self::path($category, $byId),
                'type' => $category->type->value,
                'direction' => $category->cashflow_direction->value,
            ];

            $index++;
        }

        return new self($idByIndex, $options);
    }

    /**
     * @return list<array{index: int, path: string, type: string, direction: string}>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function isEmpty(): bool
    {
        return $this->options === [];
    }

    public function categoryIdForIndex(?int $index): ?string
    {
        if ($index === null) {
            return null;
        }

        return $this->idByIndex[$index] ?? null;
    }

    /**
     * @param  Collection<string, Category>  $byId
     */
    private static function path(Category $category, Collection $byId): string
    {
        $parts = [$category->name];
        $current = $category;
        $guard = 0;

        while ($current->parent_id !== null
            && $byId->has($current->parent_id)
            && $guard++ < Category::MAX_DEPTH) {
            $current = $byId->get($current->parent_id);
            array_unshift($parts, $current->name);
        }

        return implode(' > ', $parts);
    }
}
