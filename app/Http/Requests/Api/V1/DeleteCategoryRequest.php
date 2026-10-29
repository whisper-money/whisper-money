<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CategoryDeletionStrategy;
use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedRouteModel;
use App\Models\Category;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteCategoryRequest extends FormRequest
{
    use AddsRefusals, ResolvesOwnedRouteModel;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            /**
             * What happens to the category's children: `reparent` moves them up to its parent (or makes them roots when it is one), `promote` makes them roots, `cascade` deletes them too, with their own children. Defaults to `reparent`.
             *
             * @example reparent
             */
            'strategy' => ['sometimes', Rule::enum(CategoryDeletionStrategy::class)],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }

    public function category(): Category
    {
        /** @var Category */
        return $this->routeModel();
    }

    public function strategy(): CategoryDeletionStrategy
    {
        return $this->enum('strategy', CategoryDeletionStrategy::class) ?? CategoryDeletionStrategy::Reparent;
    }

    protected function routeModelBinding(): array
    {
        return [Category::class, 'category'];
    }
}
