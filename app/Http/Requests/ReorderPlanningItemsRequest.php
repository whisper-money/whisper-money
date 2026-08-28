<?php

namespace App\Http\Requests;

use App\Models\Budget;
use App\Models\SavingsGoal;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReorderPlanningItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            'items.*.type' => ['required', 'in:budget,goal'],
            'items.*.id' => ['required', 'string'],
        ];
    }

    /**
     * The Planning list mixes two tables, so ownership cannot be one `exists`
     * rule: each row is looked up in the table its own `type` names.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            foreach ($this->input('items') as $index => $item) {
                $model = $item['type'] === 'goal' ? SavingsGoal::class : Budget::class;

                $owned = $model::query()
                    ->whereKey($item['id'])
                    ->where('user_id', $this->user()->id)
                    ->exists();

                if (! $owned) {
                    $validator->errors()->add("items.{$index}.id", __('validation.exists', ['attribute' => 'id']));
                }
            }
        }];
    }
}
