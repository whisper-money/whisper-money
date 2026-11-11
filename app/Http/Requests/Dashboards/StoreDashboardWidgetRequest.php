<?php

namespace App\Http\Requests\Dashboards;

use App\Enums\SystemWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Http\Requests\Concerns\AuthorizesDashboardUpdate;
use App\Http\Requests\Concerns\ValidatesWidgetSize;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDashboardWidgetRequest extends FormRequest
{
    use AuthorizesDashboardUpdate, ValidatesWidgetSize;

    /**
     * Without a size the widget takes its default one.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'system_widget' => ['required', Rule::enum(SystemWidget::class)],
            'width' => ['required_with:height', Rule::enum(WidgetWidth::class)],
            'height' => ['required_with:width', Rule::enum(WidgetHeight::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $widget = $this->systemWidget();

            // The registry draws each system widget once per dashboard.
            if ($this->dashboard()->widgets()->where('system_widget', $widget->value)->exists()) {
                $validator->errors()->add('system_widget', __('This widget is already on the dashboard.'));

                return;
            }

            $this->validateWidgetSize($validator, $widget);
        }];
    }

    public function systemWidget(): SystemWidget
    {
        return SystemWidget::from($this->validated('system_widget'));
    }

    /**
     * @return array{width: WidgetWidth, height: WidgetHeight}
     */
    public function size(): array
    {
        if (! $this->filled('width')) {
            return $this->systemWidget()->defaultSize();
        }

        return $this->requestedSize();
    }
}
