<?php

namespace Database\Factories;

use App\Enums\SystemWidget;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DashboardWidget>
 */
class DashboardWidgetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dashboard_id' => Dashboard::factory(),
            ...$this->systemWidgetAttributes(fake()->randomElement(SystemWidget::cases())),
            'position' => 0,
        ];
    }

    /** A system widget at its default size. */
    public function system(SystemWidget $widget): static
    {
        return $this->state(fn (): array => $this->systemWidgetAttributes($widget));
    }

    /** @return array<string, mixed> */
    private function systemWidgetAttributes(SystemWidget $widget): array
    {
        return [
            'system_widget' => $widget,
            'width' => $widget->defaultSize()['width'],
            'height' => $widget->defaultSize()['height'],
        ];
    }
}
