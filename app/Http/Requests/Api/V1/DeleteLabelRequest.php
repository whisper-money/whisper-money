<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedRouteModel;
use App\Models\Label;
use App\Services\Labels\LabelWriter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DeleteLabelRequest extends FormRequest
{
    use AddsRefusals, ResolvesOwnedRouteModel;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Refuse unknown fields, and the label of a savings goal.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...LabelWriter::savingsGoalLabelErrors($this->label(), [], deleting: true),
        ])];
    }

    public function label(): Label
    {
        /** @var Label */
        return $this->routeModel();
    }

    protected function routeModelBinding(): array
    {
        return [Label::class, 'label'];
    }
}
