<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesOwnedRouteModel;
use App\Models\Label;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Only checks that the label is the caller's to delete.
 */
class DeleteLabelRequest extends FormRequest
{
    use ResolvesOwnedRouteModel;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
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
