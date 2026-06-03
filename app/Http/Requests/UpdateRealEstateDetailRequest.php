<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountDetailRules;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRealEstateDetailRequest extends FormRequest
{
    use ValidatesAccountDetailRules, ValidatesUserOwnedResources;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->realEstateDetailRules(propertyTypeSometimes: true);
    }
}
