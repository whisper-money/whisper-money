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
        return [
            ...$this->labelFields(LabelWriter::updateRules()),
            /**
             * `true` to archive the label, `false` to unarchive it. An archived label stays on the transactions, automation rules and budgets that already use it and keeps working in filters and reports, but cannot be added to anything new. The label of a savings goal cannot be archived on its own.
             *
             * @example true
             */
            'archived' => LabelWriter::updateRules()['archived'],
        ];
    }

    /**
     * Refuse unknown fields, and renaming or archiving the label of a savings
     * goal.
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
