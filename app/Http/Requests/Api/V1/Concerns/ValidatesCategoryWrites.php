<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Category;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * What the category create and update requests share: the CategoryWriter
 * rules the MCP tools validate with too, and the parent they point at,
 * checked to be in the request's space.
 */
trait ValidatesCategoryWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    /**
     * @param  array<string, array<mixed>>  $rules  the CategoryWriter rules for the write
     * @return array<string, array<mixed>>
     */
    protected function categoryFields(array $rules): array
    {
        return [
            /**
             * The category's name, unique among its siblings: two categories can share a name only under different parents. Your categories at one level of the tree also need different names across all your spaces.
             *
             * @example Groceries
             */
            'name' => $rules['name'],
            /**
             * The name of the icon the app shows for it, a Lucide icon name.
             *
             * @example ShoppingBag
             */
            'icon' => $rules['icon'],
            /**
             * The category's color.
             *
             * @example green
             */
            'color' => $rules['color'],
            /**
             * What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. Ignored on a child, which always takes its parent's type.
             *
             * @example expense
             */
            'type' => $rules['type'],
            /**
             * How the category's transactions count in the cashflow. Only a root `transfer` category keeps the one you send: a `savings` or `investment` root is always `outflow`, any other root `hidden`, and a child takes its parent's.
             *
             * @example outflow
             */
            'cashflow_direction' => $rules['cashflow_direction'],
            /**
             * The id of the category to nest it under, or `null` for a root. It must be one you created: a tree stays with the member who built it. Categories nest up to 3 levels deep, and a category cannot sit under itself or one of its own children.
             *
             * @format uuid
             */
            'parent_id' => ['bail', 'sometimes', 'nullable', 'string', $this->inSpace(Category::class, 'a category'), $this->ownCategory(...)],
        ];
    }

    /**
     * The category tree is kept per member, so a category cannot be nested
     * under one another member of the space created.
     */
    private function ownCategory(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var User $user */
        $user = $this->user();

        if (Category::query()->whereKey($value)->value('user_id') !== $user->id) {
            $fail('The :attribute is a category another member of the space created. Nest it under one of your own.');
        }
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }
}
