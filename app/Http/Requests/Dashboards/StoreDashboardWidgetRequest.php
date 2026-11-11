<?php

namespace App\Http\Requests\Dashboards;

use App\Enums\SystemWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use App\Http\Requests\Concerns\AuthorizesDashboardUpdate;
use App\Http\Requests\Concerns\ValidatesWidgetSize;
use App\Models\Dashboard;
use App\Models\WidgetDefinition;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Places a system widget (`system_widget`) or one of the user's own
 * (`widget_definition_id`) on the dashboard.
 */
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
            'system_widget' => ['required_without:widget_definition_id', 'prohibits:widget_definition_id', Rule::enum(SystemWidget::class)],
            'widget_definition_id' => [
                'required_without:system_widget',
                'uuid',
                Rule::exists('widget_definitions', 'id')->where('user_id', $this->user()->id),
            ],
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

            if ($this->dashboard()->widgets()->count() >= Dashboard::MAX_WIDGETS) {
                $validator->errors()->add('dashboard', __('A dashboard holds at most :count widgets.', ['count' => Dashboard::MAX_WIDGETS]));

                return;
            }

            $widget = $this->widget();

            // The registry draws each system widget once per dashboard.
            if ($widget instanceof SystemWidget && $this->dashboard()->widgets()->where('system_widget', $widget->value)->exists()) {
                $validator->errors()->add('system_widget', __('This widget is already on the dashboard.'));

                return;
            }

            $this->validateWidgetSize($validator, $widget);
        }];
    }

    /**
     * The widget asked for, once validated.
     */
    public function widget(): SystemWidget|WidgetDefinition
    {
        return once(fn (): SystemWidget|WidgetDefinition => $this->filled('widget_definition_id')
            ? WidgetDefinition::query()->findOrFail($this->validated('widget_definition_id'))
            : SystemWidget::from($this->validated('system_widget')));
    }
}
