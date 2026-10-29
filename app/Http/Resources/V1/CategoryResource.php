<?php

namespace App\Http\Resources\V1;

use App\Models\Category;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category: what a transaction was for. Categories form a tree up to 3
 * levels deep through `parent_id`.
 *
 * @mixin Category
 */
#[SchemaName('Category')]
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * The category's id.
             *
             * @format uuid
             *
             * @example 0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12
             */
            'id' => $this->id,
            /**
             * The id of the space the category is in.
             *
             * @format uuid
             *
             * @example 0199a3f2-6c1e-7b4d-9a8e-2f5c1d3b4a60
             */
            'space_id' => $this->space_id,
            /**
             * The category's name, unique among its siblings.
             *
             * @example Groceries
             */
            'name' => $this->name,
            /**
             * The name of the icon the app shows for it, a Lucide icon name.
             *
             * @example ShoppingBag
             */
            'icon' => $this->icon,
            /**
             * The category's color.
             *
             * @example green
             */
            'color' => $this->color,
            /**
             * What the category's transactions are: `income`, `expense`, `transfer`, `savings` or `investment`. A whole tree shares its root's type.
             *
             * @example expense
             */
            'type' => $this->type->value,
            /**
             * How the category's transactions count in the cashflow: `inflow`, `outflow`, or `hidden` to leave them out of it. A whole tree shares its root's direction.
             *
             * @example hidden
             */
            'cashflow_direction' => $this->cashflow_direction->value,
            /**
             * The id of the category it is nested under, or `null` for a root.
             *
             * @format uuid
             *
             * @example null
             */
            'parent_id' => $this->parent_id,
            /**
             * When the category was created.
             *
             * @format date-time
             *
             * @example 2026-01-15T09:30:00+00:00
             */
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
