<?php

namespace App\Http\Requests\Concerns;

use App\Contracts\SizedWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use Illuminate\Contracts\Validation\Validator;

/**
 * Each widget admits only some of the six sizes: a chart that needs the room
 * is never offered a third of the row. A user widget's manifest names its own.
 */
trait ValidatesWidgetSize
{
    protected function validateWidgetSize(Validator $validator, SizedWidget $widget): void
    {
        if (! $this->filled('width')) {
            return;
        }

        $size = $this->size();

        if (! $widget->allowsSize($size['width'], $size['height'])) {
            $validator->errors()->add('width', __('This widget is not available in that size.'));
        }
    }

    /**
     * The size asked for, or the widget's default one when none was.
     *
     * @return array{width: WidgetWidth, height: WidgetHeight}
     */
    public function size(?SizedWidget $widget = null): array
    {
        if ($widget !== null && ! $this->filled('width')) {
            return $widget->defaultSize();
        }

        return [
            'width' => WidgetWidth::from($this->input('width')),
            'height' => WidgetHeight::from($this->input('height')),
        ];
    }
}
