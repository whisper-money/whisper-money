<?php

namespace App\Mcp\Tools;

use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the user\'s categories in a space. Categories form a tree via parent_id; use the ids to filter search_transactions or spending_by_category.')]
class ListCategories extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);

        $categories = Category::query()
            ->forSpace($space)
            ->orderBy('name')
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $category->type->value,
                'cashflow_direction' => $category->cashflow_direction->value,
                'parent_id' => $category->parent_id,
            ]);

        return $this->json([
            'space_id' => $space->id,
            'categories' => $categories,
        ]);
    }
}
