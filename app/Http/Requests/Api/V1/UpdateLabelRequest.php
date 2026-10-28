<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedRouteModel;
use App\Http\Requests\Api\V1\Concerns\ValidatesLabelWrites;
use App\Models\Label;
use App\Services\Labels\LabelWriter;
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
