<?php

namespace Database\Factories;

use App\Enums\WidgetFrame;
use App\Enums\WidgetOrigin;
use App\Models\User;
use App\Models\WidgetDefinition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A widget as if already saved and compiled, at its first revision. The
 * module is a stand-in: tests that need a real one save the widget through
 * WidgetDefinitionService instead.
 *
 * @extends Factory<WidgetDefinition>
 */
class WidgetDefinitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'user_id' => User::factory(),
            'name' => $name,
            'manifest' => [
                'name' => $name,
                'size' => ['width' => '1/2', 'height' => 'm', 'allowed_widths' => ['1/2', '1/1']],
                'data' => [
                    'accounts' => ['endpoint' => 'GET /accounts'],
                ],
            ],
            'source' => 'export default function Widget() { return <div />; }',
            'compiled_js' => 'import{jsx as e}from"react/jsx-runtime";function t(){return e("div",{})}export{t as default};',
            'revision' => 1,
            'created_via' => WidgetOrigin::Mcp,
        ];
    }

    /**
     * @param  array<string, array{endpoint: string, params?: array<string, mixed>, paginate?: 'all'}>  $data
     */
    public function reading(array $data): static
    {
        return $this->state(fn (array $attributes): array => [
            'manifest' => [...$attributes['manifest'], 'data' => $data],
        ]);
    }

    /** Drawn without the dashboard's card around it. */
    public function frameless(): static
    {
        return $this->state(fn (array $attributes): array => [
            'manifest' => [...$attributes['manifest'], 'frame' => WidgetFrame::None->value],
        ]);
    }

    /** Resizable between the medium height and the height of its content. */
    public function autoHeight(): static
    {
        return $this->state(fn (array $attributes): array => [
            'manifest' => [...$attributes['manifest'], 'size' => [...$attributes['manifest']['size'], 'allowed_heights' => ['m', 'a']]],
        ]);
    }

    /** Its first revision, matching what the factory stored. */
    public function configure(): static
    {
        return $this->afterCreating(function (WidgetDefinition $widget): void {
            $widget->revisions()->create([
                'number' => $widget->revision,
                'manifest' => $widget->manifest,
                'source' => $widget->source,
                'compiled_js' => $widget->compiled_js,
                'created_via' => $widget->created_via,
            ]);
        });
    }
}
