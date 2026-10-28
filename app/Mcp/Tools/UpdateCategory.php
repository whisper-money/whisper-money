<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryColor;
use App\Enums\CategoryType;
use App\Mcp\Tools\Concerns\ResolvesCategoryWrites;
use App\Models\User;
use App\Services\Categories\CategoryWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit a category. Only the fields you pass are changed. Moving it under a parent (or clearing parent_id to make it a root) re-derives its type/cashflow and cascades the type to its descendants.')]
class UpdateCategory extends WriteTool
{
    use ResolvesCategoryWrites;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'category_id' => $schema->string()->description('Id of the category to edit.')->required(),
            'name' => $schema->string()->description('New category name.'),
            'icon' => $schema->string()->description('New icon name.'),
            'color' => $schema->string()->enum(array_column(CategoryColor::cases(), 'value'))->description('New category color.'),
            'type' => $schema->string()->enum(array_column(CategoryType::cases(), 'value'))->description('New type (ignored while the category has a parent).'),
            'parent_id' => $schema->string()->description('New parent id, or null to make it a root.'),
            'cashflow_direction' => $schema->string()->enum(array_column(CategoryCashflowDirection::cases(), 'value'))->description('Only used for transfer-type roots; otherwise derived automatically.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(CategoryWriter::updateRules());

        $space = $this->resolveSpace($request, $user);
        $category = $this->categoryInSpace($request, $space);

        $category = app(CategoryWriter::class)->update(
            $category,
            $space,
            $request->all(),
            $this->parentCategory($request, $space),
        );

        return $this->json(['category' => $this->presentCategory($category)]);
    }
}
