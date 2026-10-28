<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class SpendingByCategoryRequest extends AnalyticsRangeRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            /**
             * Drill into one of your categories: its children are returned instead of the top-level categories.
             *
             * @format uuid
             */
            'parent_category_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('user_id', $this->user()?->getAuthIdentifier())->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_category_id.exists' => 'The parent_category_id is not one of your categories.',
        ];
    }

    public function parentCategoryId(): ?string
    {
        return $this->validated('parent_category_id');
    }
}
