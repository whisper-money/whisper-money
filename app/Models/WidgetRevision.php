<?php

namespace App\Models;

use App\Enums\WidgetOrigin;
use Database\Factories\WidgetRevisionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved version of a user widget. Going back to an earlier one saves a
 * new revision with its content, noting which one it came from.
 *
 * @property string $id
 * @property string $widget_definition_id
 * @property int $number
 * @property array<string, mixed> $manifest
 * @property string $source
 * @property string $compiled_js
 * @property WidgetOrigin $created_via
 * @property int|null $restored_from
 */
class WidgetRevision extends Model
{
    /** @use HasFactory<WidgetRevisionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'widget_definition_id',
        'number',
        'manifest',
        'source',
        'compiled_js',
        'created_via',
        'restored_from',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'manifest' => 'array',
            'created_via' => WidgetOrigin::class,
            'restored_from' => 'integer',
        ];
    }

    /** @return BelongsTo<WidgetDefinition, $this> */
    public function widgetDefinition(): BelongsTo
    {
        return $this->belongsTo(WidgetDefinition::class);
    }
}
