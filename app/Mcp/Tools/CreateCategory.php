<?php

namespace App\Mcp\Tools;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryColor;
use App\Enums\CategoryType;
use App\Mcp\Tools\Concerns\ResolvesCategoryWrites;
use App\Models\Category;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
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
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'icon' => ['required', 'string'],
            'color' => ['required', Rule::enum(CategoryColor::class)],
            'type' => ['required', Rule::enum(CategoryType::class)],
            'cashflow_direction' => ['sometimes', Rule::enum(CategoryCashflowDirection::class)],
        ]);

        $space = $this->resolveSpace($request, $user);
        $parent = $this->resolveParentCategory($request, $space, null);

        $type = $parent !== null ? $parent->type : $request->enum('type', CategoryType::class);
        $cashflow = $this->cashflowDirectionFor($type, $parent, $request->string('cashflow_direction')->toString());

        $name = $request->string('name')->toString();

        if ($this->categoryNameTaken($space, $name, $parent?->id)) {
            throw ValidationException::withMessages([
                'name' => 'A category with that name already exists at this level.',
            ]);
        }

        $category = new Category([
            'user_id' => $user->id,
            'space_id' => $space->id,
            'name' => $name,
            'icon' => $request->string('icon')->toString(),
            'color' => $request->string('color')->toString(),
            'type' => $type->value,
            'cashflow_direction' => $cashflow->value,
            'parent_id' => $parent?->id,
        ]);
        $category->save();

        return $this->json(['category' => $this->presentCategory($category)]);
    }
}
