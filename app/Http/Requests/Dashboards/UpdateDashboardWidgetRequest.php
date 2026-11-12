<?php

namespace App\Http\Requests\Dashboards;

use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Http\Requests\Concerns\AuthorizesDashboardUpdate;
use App\Http\Requests\Concerns\ValidatesWidgetSize;
use App\Models\DashboardWidget;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardWidgetRequest extends FormRequest
{
    use AuthorizesDashboardUpdate, ValidatesWidgetSize;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'width' => ['required', Rule::enum(WidgetWidth::class)],
            'height' => ['required', Rule::enum(WidgetHeight::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            /** @var DashboardWidget $widget */
            $widget = $this->route('widget');

            if ($validator->errors()->isEmpty() && $widget->system_widget !== null) {
                $this->validateWidgetSize($validator, $widget->system_widget);
            }
        }];
    }
}
