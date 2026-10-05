<?php

namespace App\Models;

use App\Enums\ImportChunkKind;
use App\Enums\ImportMode;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One full import from another app: the history row Settings lists, the
 * progress the wizard polls, and the anchor every row it wrote points back at.
 *
 * @property ImportSource $source
 * @property ImportMode $mode
 * @property ImportStatus $status
 * @property ?array<string, mixed> $plan
 * @property ?array<string, mixed> $options
 * @property ?array<string, mixed> $stats
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 * @property ?Carbon $undone_at
 */
class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use BelongsToSpace, HasFactory, HasUuids, MassPrunable;

    protected $fillable = [
        'user_id',
        'space_id',
        'source',
        'file_name',
        'mode',
        'status',
        'plan',
        'options',
        'stats',
        'error',
        'started_at',
        'finished_at',
        'undone_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'user_id',
        'space_id',
        'plan',
        'options',
    ];

    protected function casts(): array
    {
        return [
            'source' => ImportSource::class,
            'mode' => ImportMode::class,
            'status' => ImportStatus::class,
            'plan' => 'array',
            'options' => 'array',
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }

    /**
     * Drafts abandoned mid-upload: nothing was written from them, and their
     * staged rows (cascaded with them) are the user's file, which is not kept
     * for an import that never ran.
     *
     * @return Builder<Import>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', ImportStatus::Draft->value)
            ->where('updated_at', '<', now()->subDay());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ImportChunk, $this> */
    public function chunks(): HasMany
    {
        return $this->hasMany(ImportChunk::class);
    }

    /** @return HasMany<Account, $this> */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** @return HasMany<AccountBalance, $this> */
    public function balances(): HasMany
    {
        return $this->hasMany(AccountBalance::class);
    }

    /**
     * Imports whose rows are still in the user's data: finished, one way or the
     * other, and not undone. A failed import counts, because whatever it wrote
     * before failing is still there to take back out.
     *
     * @param  Builder<Import>  $query
     * @return Builder<Import>
     */
    public function scopeUndoable(Builder $query): Builder
    {
        return $query
            ->whereNull('undone_at')
            ->whereIn('status', [ImportStatus::Completed->value, ImportStatus::Failed->value]);
    }

    /**
     * Imports a worker has, or is about to have.
     *
     * @param  Builder<Import>  $query
     * @return Builder<Import>
     */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->whereIn('status', [ImportStatus::Queued->value, ImportStatus::Processing->value]);
    }

    /** How many rows of a kind the browser has staged so far. */
    public function stagedRowCount(ImportChunkKind $kind): int
    {
        return (int) $this->chunks()->where('kind', $kind->value)->sum('row_count');
    }

    /**
     * Hand the staged chunks of a kind over one at a time, in the order the
     * browser cut the file into them. Each is read on its own, so a decade of
     * movements is never held in memory at once.
     *
     * @param  callable(ImportChunk): void  $callback
     */
    public function eachChunk(ImportChunkKind $kind, callable $callback): void
    {
        $ids = $this->chunks()->where('kind', $kind->value)->orderBy('position')->pluck('id');

        foreach ($ids as $id) {
            $chunk = ImportChunk::query()->find($id);

            if ($chunk !== null) {
                $callback($chunk);
            }
        }
    }

    public function isUndoable(): bool
    {
        return $this->undone_at === null && $this->status->isFinished();
    }

    /**
     * Merge counts into the stats the progress screen polls, writing them at
     * once so a closed tab finds them on its next visit.
     *
     * @param  array<string, mixed>  $stats
     */
    public function recordStats(array $stats): void
    {
        $this->forceFill(['stats' => array_replace_recursive($this->stats ?? [], $stats)])->save();
    }
}
