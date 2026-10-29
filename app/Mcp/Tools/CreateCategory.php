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

#[Description('Create a category. A child (parent_id set) inherits its parent type and cashflow direction; a root follows its own type. Categories can be nested up to 3 levels deep.')]
class CreateCategory extends WriteTool
{
    use ResolvesCategoryWrites;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Category name (unique among its siblings).')->required(),
            'icon' => $schema->string()->description('Icon name (e.g. ShoppingBag, Home, Car).')->required(),
            'color' => $schema->string()->enum(array_column(CategoryColor::cases(), 'value'))->description('Category color.')->required(),
            'type' => $schema->string()->enum(array_column(CategoryType::cases(), 'value'))->description('Category type. Ignored for children (they inherit the parent type).')->required(),
            'parent_id' => $schema->string()->description('Optional parent category id to nest under.'),
            'cashflow_direction' => $schema->string()->enum(array_column(CategoryCashflowDirection::cases(), 'value'))->description('Only used for transfer-type roots; otherwise derived automatically.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(CategoryWriter::createRules());

        $space = $this->resolveSpace($request, $user);

        $category = app(CategoryWriter::class)->create(
            $user,
            $space,
            $request->all(),
            $this->parentCategory($request, $space),
        );

        return $this->json(['category' => $this->presentCategory($category)]);
    }
}
