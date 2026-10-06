<?php

namespace App\Models;

use App\Enums\ImportChunkKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A slice of a full import's rows, staged between the browser uploading it and
 * the queued job writing it. Deleted once the job is done with the import.
 *
 * @property ImportChunkKind $kind
 * @property int $position
 * @property int $row_count
 * @property list<array<string, mixed>> $rows
 */
class ImportChunk extends Model
{
    protected $fillable = [
        'import_id',
        'kind',
        'position',
        'row_count',
        'rows',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ImportChunkKind::class,
            'position' => 'integer',
            'row_count' => 'integer',
            'rows' => 'array',
        ];
    }

    /** @return BelongsTo<Import, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }
}
