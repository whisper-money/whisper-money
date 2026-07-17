<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryColor;
use App\Enums\CategoryType;
use App\Mcp\Tools\Concerns\ResolvesCategoryWrites;
use App\Models\Category;
use App\Models\Space;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
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
        $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'icon' => ['sometimes', 'string'],
            'color' => ['sometimes', Rule::enum(CategoryColor::class)],
            'type' => ['sometimes', Rule::enum(CategoryType::class)],
            'cashflow_direction' => ['sometimes', Rule::enum(CategoryCashflowDirection::class)],
        ]);

        $space = $this->resolveSpace($request, $user);
        $category = $this->categoryInSpace($request, $space);

        $parent = $request->has('parent_id')
            ? $this->resolveParentCategory($request, $space, $category)
            : $this->currentParent($category, $space);

        $type = $parent !== null
            ? $parent->type
            : ($request->has('type') ? $request->enum('type', CategoryType::class) : $category->type);

        $requestedDirection = $request->filled('cashflow_direction')
            ? $request->string('cashflow_direction')->toString()
            : $category->cashflow_direction->value;
        $cashflow = $this->cashflowDirectionFor($type, $parent, $requestedDirection);

        $name = $request->has('name') ? $request->string('name')->toString() : $category->name;

        if ($this->categoryNameTaken($space, $name, $parent?->id, $category->id)) {
            throw ValidationException::withMessages([
                'name' => 'A category with that name already exists at this level.',
            ]);
        }

        $category->fill([
            'name' => $name,
            'icon' => $request->has('icon') ? $request->string('icon')->toString() : $category->icon,
            'color' => $request->has('color') ? $request->string('color')->toString() : $category->color,
            'type' => $type->value,
            'cashflow_direction' => $cashflow->value,
            'parent_id' => $parent?->id,
        ]);
        $category->save();

        (new CategoryTree)->syncDescendantTypes($category);

        return $this->json(['category' => $this->presentCategory($category->refresh())]);
    }

    private function currentParent(Category $category, Space $space): ?Category
    {
        if ($category->parent_id === null) {
            return null;
        }

        return Category::query()->forSpace($space)->whereKey($category->parent_id)->first();
    }
}
