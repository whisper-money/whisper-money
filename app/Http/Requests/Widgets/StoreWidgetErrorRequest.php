<?php

namespace App\Http\Requests\Widgets;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A runtime error a user widget raised on the dashboard, as the runtime's
 * error boundary reports it (`WidgetRuntimeError`), with the revision that
 * ran. The sizes match what the runtime already trims to.
 */
class StoreWidgetErrorRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'phase' => ['required', 'in:load,render'],
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:8000'],
            'componentStack' => ['nullable', 'string', 'max:8000'],
        ];
    }

    /**
     * @return array{phase: string, message: string, stack: string|null, component_stack: string|null}
     */
    public function error(): array
    {
        return [
            'phase' => $this->validated('phase'),
            'message' => $this->validated('message'),
            'stack' => $this->validated('stack'),
            'component_stack' => $this->validated('componentStack'),
        ];
    }
}
