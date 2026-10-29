<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedRouteModel;
use App\Http\Requests\Api\V1\Concerns\ValidatesLabelWrites;
use App\Models\Label;
use App\Services\Labels\LabelWriter;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLabelRequest extends FormRequest
{
    use ResolvesOwnedRouteModel, ValidatesLabelWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->labelFields(LabelWriter::updateRules());
    }

    /**
     * Refuse unknown fields, and renaming the label of a savings goal.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...LabelWriter::savingsGoalLabelErrors($this->label(), $this->sentFields(), deleting: false),
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
