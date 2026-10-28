<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedRouteModel;
use App\Http\Requests\Api\V1\Concerns\ValidatesCategoryWrites;
use App\Models\Category;
use App\Services\Categories\CategoryWriter;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    use ResolvesOwnedRouteModel, ValidatesCategoryWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->categoryFields(CategoryWriter::updateRules());
    }

    public function category(): Category
    {
        /** @var Category */
        return $this->routeModel();
    }

    protected function routeModelBinding(): array
    {
        return [Category::class, 'category'];
    }
}
