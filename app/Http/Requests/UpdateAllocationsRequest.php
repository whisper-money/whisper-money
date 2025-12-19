<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAllocationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'allocations' => ['required', 'array'],
            'allocations.*.budget_category_id' => ['required', 'exists:budget_categories,id'],
            'allocations.*.allocated_amount' => ['required', 'integer', 'min:0'],
        ];
    }
}
