<?php

namespace Database\Factories;

use App\Enums\WidgetOrigin;
use App\Models\WidgetDefinition;
use App\Models\WidgetRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WidgetRevision>
 */
class WidgetRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'widget_definition_id' => WidgetDefinition::factory(),
            'number' => 2,
            'manifest' => [
                'name' => 'Widget',
                'size' => ['width' => '1/2', 'height' => 'm'],
                'data' => ['accounts' => ['endpoint' => 'GET /accounts']],
            ],
            'source' => 'export default function Widget() { return <span />; }',
            'compiled_js' => 'export default function t(){return null}',
            'created_via' => WidgetOrigin::Mcp,
        ];
    }
}
