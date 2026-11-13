<?php

namespace App\Models;

use App\Contracts\SizedWidget;
use App\Enums\WidgetFrame;
use App\Enums\WidgetHeight;
use App\Enums\WidgetOrigin;
use App\Enums\WidgetWidth;
use Database\Factories\WidgetDefinitionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A widget the user's agent wrote: a manifest (what data it reads and the
 * sizes it admits) and TSX, compiled to the ES module the dashboard loads.
 * Its columns hold the current revision; {@see WidgetRevision} keeps them all.
 * Only App\Services\Widgets\WidgetDefinitionService writes it.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property array{name: string, size: array{width: string, height: string, allowed_widths?: list<string>, allowed_heights?: list<string>}, frame?: 'card'|'none', data: array<string, array{endpoint: string, params?: array<string, mixed>, paginate?: 'all'}>} $manifest
 * @property string $source
 * @property string $compiled_js
 * @property int $revision
 * @property array{revision: int, phase: string, message: string, stack: string|null, component_stack: string|null}|null $last_error
 * @property Carbon|null $last_error_at
 * @property WidgetOrigin $created_via
 */
class WidgetDefinition extends Model implements SizedWidget
{
    /** @use HasFactory<WidgetDefinitionFactory> */
    use HasFactory, HasUuids;

    /** How many widgets a user may keep. */
    public const MAX_PER_USER = 100;

    protected $fillable = [
        'user_id',
        'name',
        'manifest',
        'source',
        'compiled_js',
        'revision',
        'last_error',
        'last_error_at',
        'created_via',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'revision' => 'integer',
            'last_error' => 'array',
            'last_error_at' => 'datetime',
            'created_via' => WidgetOrigin::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<WidgetRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(WidgetRevision::class);
    }

    /**
     * Where it is placed, on any of the user's dashboards.
     *
     * @return HasMany<DashboardWidget, $this>
     */
    public function placements(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }

    /**
     * The widgets placed on $dashboard, each once however many times it is placed.
     *
     * @param  Builder<WidgetDefinition>  $query
     */
    public function scopeOnDashboard(Builder $query, Dashboard $dashboard): void
    {
        $query->whereIn('id', $dashboard->widgets()->whereNotNull('widget_definition_id')->select('widget_definition_id'));
    }

    /** @return array{width: WidgetWidth, height: WidgetHeight} */
    public function defaultSize(): array
    {
        return [
            'width' => WidgetWidth::from($this->manifest['size']['width']),
            'height' => WidgetHeight::from($this->manifest['size']['height']),
        ];
    }

    public function frame(): WidgetFrame
    {
        return WidgetFrame::from($this->manifest['frame'] ?? WidgetFrame::Card->value);
    }

    public function allowsSize(WidgetWidth $width, WidgetHeight $height): bool
    {
        $size = $this->manifest['size'];

        return in_array($width->value, $size['allowed_widths'] ?? [$size['width']], true)
            && in_array($height->value, $size['allowed_heights'] ?? [$size['height']], true);
    }
}
