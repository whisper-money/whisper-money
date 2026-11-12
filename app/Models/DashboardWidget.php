<?php

namespace App\Models;

use App\Contracts\SizedWidget;
use App\Enums\SystemWidget;
use App\Enums\WidgetHeight;
use App\Enums\WidgetWidth;
use Database\Factories\DashboardWidgetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One widget placed on a dashboard: which widget, where and at what size.
 * Either a system widget (`system_widget`) or one the user's agent wrote
 * (`widget_definition_id`).
 *
 * @property string $id
 * @property string $dashboard_id
 * @property SystemWidget|null $system_widget
 * @property string|null $widget_definition_id
 * @property int $position
 * @property WidgetWidth $width
 * @property WidgetHeight $height
 * @property array<string, mixed>|null $config
 */
class DashboardWidget extends Model
{
    /** @use HasFactory<DashboardWidgetFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'dashboard_id',
        'system_widget',
        'widget_definition_id',
        'position',
        'width',
        'height',
        'config',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_widget' => SystemWidget::class,
            'width' => WidgetWidth::class,
            'height' => WidgetHeight::class,
            'config' => 'array',
        ];
    }

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    /** @return BelongsTo<WidgetDefinition, $this> */
    public function widgetDefinition(): BelongsTo
    {
        return $this->belongsTo(WidgetDefinition::class);
    }

    /**
     * The widget it draws, which decides the sizes it admits. Null only for a
     * row whose system widget id is unknown, which the casts already reject.
     */
    public function sizedWidget(): ?SizedWidget
    {
        return $this->system_widget ?? $this->widgetDefinition;
    }
}
