<?php

namespace App\Http\Resources\V1;

use App\Models\Category;
use Dedoc\Scramble\Attributes\SchemaName;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What one category adds up to in a period, its subcategories rolled into it.
 *
 * @property array{category_id: ?string, category: Category|null, amount: int, has_children?: bool, is_direct?: bool} $resource
 */
#[SchemaName('CategoryAmount')]
class CategoryAmountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource['category'];

        return [
            /**
             * The category's id, or `null` for the row that gathers the transactions with no category.
             *
             * @var string|null
             *
             * @format uuid
             *
             * @example 0199a3f5-2b3c-7d4e-8f5a-6b7c8d9e0f12
             */
            'category_id' => $this->resource['category_id'],
            /**
             * The category's name. The row with no category is `Unknown Income` or `Unknown Expense`. On the cashflow's top level, a category whose net crossed to the other side says so: `Groceries (refund)`, `Savings (withdrawal)`, `Salary (reversal)`.
             *
             * @var string|null
             *
             * @example Groceries
             */
            'name' => $category?->name,
            /**
             * The category's type: `income`, `expense`, `savings`, `investment` or `transfer`.
             *
             * @var string|null
             *
             * @example expense
             */
            'type' => $category?->type?->value,
            /**
             * The category's colour in the app.
             *
             * @var string|null
             *
             * @example orange
             */
            'color' => $category?->color,
            /**
             * The category's icon in the app, a Lucide icon name.
             *
             * @var string|null
             *
             * @example ShoppingCart
             */
            'icon' => $category?->icon,
            /**
             * What the category adds up to over the period, as a positive amount in minor units of `currency`.
             *
             * @var int
             *
             * @example 15345
             */
            'amount' => $this->resource['amount'],
            /**
             * Whether the category has subcategories to drill into.
             *
             * @var bool
             *
             * @example false
             */
            'has_children' => $this->resource['has_children'] ?? false,
            /**
             * When drilling into a category, `true` on the row for the transactions booked to that category itself rather than to one of its children.
             *
             * @var bool
             *
             * @example false
             */
            'is_direct' => $this->resource['is_direct'] ?? false,
        ];
    }
}
