<?php

namespace App\Http\Requests\Concerns;

use App\Enums\SystemWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use Illuminate\Contracts\Validation\Validator;

/**
 * Each system widget admits only some of the six sizes: a chart that needs the
 * room is never offered a third of the row.
 */
trait ValidatesWidgetSize
{
    protected function validateWidgetSize(Validator $validator, SystemWidget $widget): void
    {
        if (! $this->filled('width')) {
            return;
        }

        $size = $this->requestedSize();

        if (! $widget->allowsSize($size['width'], $size['height'])) {
            $validator->errors()->add('width', __('This widget is not available in that size.'));
        }
    }

    /**
     * @return array{width: WidgetWidth, height: WidgetHeight}
     */
    public function requestedSize(): array
    {
        return [
            'width' => WidgetWidth::from($this->input('width')),
            'height' => WidgetHeight::from($this->input('height')),
        ];
    }
}
