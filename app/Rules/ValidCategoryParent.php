<?php

namespace App\Rules;

use App\Models\Category;
use App\Services\CategoryTree;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidCategoryParent implements ValidationRule
{
    /**
     * @param  Category|null  $category  The category being moved (null when creating).
     */
    public function __construct(
        private readonly ?Category $category,
        private readonly CategoryTree $tree = new CategoryTree,
    ) {}

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $parent = Category::query()
            ->where('user_id', auth()->id())
            ->whereKey($value)
            ->first();

        if ($parent === null) {
            $fail(__('The selected parent category is invalid.'));

            return;
        }

        if ($this->category !== null && $this->tree->wouldCreateCycle($this->category, $parent->id)) {
            $fail(__('A category cannot be nested under itself or one of its children.'));

            return;
        }

        $subtreeDepth = $this->category !== null
            ? $this->tree->subtreeDepth($this->category)
            : 1;

        if ($this->tree->depth($parent) + $subtreeDepth > Category::MAX_DEPTH) {
            $fail(__('Categories can only be nested up to :max levels deep.', ['max' => Category::MAX_DEPTH]));
        }
    }
}
