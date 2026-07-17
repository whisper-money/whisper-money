<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryDeletionStrategy;
use App\Models\Category;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a category. The strategy decides what happens to its child categories: "reparent" (default) lifts them to the deleted category\'s parent, "promote" turns them into roots, "cascade" deletes the whole subtree and uncategorizes affected transactions.')]
class DeleteCategory extends WriteTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->string()->description('Id of the category to delete.')->required(),
            'strategy' => $schema->string()->enum(array_column(CategoryDeletionStrategy::cases(), 'value'))->description('How to handle child categories. Defaults to "reparent".'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'strategy' => ['sometimes', Rule::enum(CategoryDeletionStrategy::class)],
        ]);

        $space = $this->resolveSpace($request, $user);
        $category = $this->categoryInSpace($request, $space);

        $strategy = $request->enum('strategy', CategoryDeletionStrategy::class) ?? CategoryDeletionStrategy::Reparent;
        $tree = new CategoryTree;

        match ($strategy) {
            CategoryDeletionStrategy::Cascade => $tree->deleteSubtree($category),
            CategoryDeletionStrategy::Promote => $this->detachChildrenAndDelete($category, null),
            CategoryDeletionStrategy::Reparent => $this->detachChildrenAndDelete($category, $category->parent_id),
        };

        return $this->json(['deleted' => true, 'id' => $category->id, 'strategy' => $strategy->value]);
    }

    /**
     * Move the category's direct children to a new parent, then delete it.
     */
    private function detachChildrenAndDelete(Category $category, ?string $newParentId): void
    {
        try {
            $category->children()->update(['parent_id' => $newParentId]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'strategy' => 'A category with the same name already exists at the destination level. Rename it first.',
            ]);
        }

        $category->delete();
    }
}
