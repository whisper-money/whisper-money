<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryDeletionStrategy;
use App\Models\User;
use App\Services\Categories\CategoryWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a category, uncategorizing its transactions. strategy decides its children: "reparent" (default) lifts them to its parent, "promote" makes them roots, "cascade" deletes the subtree too.')]
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

        app(CategoryWriter::class)->delete($category, $strategy);

        return $this->json(['deleted' => true, 'id' => $category->id, 'strategy' => $strategy->value]);
    }
}
