<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Category;
use App\Models\Space;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

/**
 * The parent lookup shared by the category write tools. Where the category
 * may then sit in the tree, and the type and cashflow direction it derives
 * from its parent, is CategoryWriter's to decide.
 */
trait ResolvesCategoryWrites
{
    /**
     * The requested parent category within the space, or null when the
     * request makes (or keeps) a root or names no parent.
     */
    protected function parentCategory(Request $request, Space $space): ?Category
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

        return $parent;
    }
}
