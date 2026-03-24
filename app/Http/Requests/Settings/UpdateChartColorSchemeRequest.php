<?php

namespace App\Http\Requests\Settings;

use App\Enums\ChartColorScheme;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateChartColorSchemeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'chart_color_scheme' => ['required', 'string', Rule::enum(ChartColorScheme::class)],
        ];
    }
}
