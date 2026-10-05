<?php

namespace App\Models;

use App\Enums\ImportChunkKind;
use App\Enums\ImportMode;
use App\Enums\ImportSource;
use App\Enums\ImportStage;
use App\Enums\ImportStatus;
use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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
    use BelongsToSpace, HasFactory, HasUuids, Prunable;

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
     * The movements and balances an import wrote point back at it through a
     * plain column, not a foreign key (those tables are too large to add one
     * to), so deleting the history row clears the link here instead. Pruning
     * goes through this too: it deletes model by model.
     */
    protected static function booted(): void
    {
        static::deleting(function (Import $import): void {
            Transaction::withTrashed()->where('import_id', $import->id)->update(['import_id' => null]);
            AccountBalance::query()->where('import_id', $import->id)->update(['import_id' => null]);
        });
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
        return Import::query()
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

    /** @return HasMany<Bank, $this> */
    public function banks(): HasMany
    {
        return $this->hasMany(Bank::class);
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
     * Imports whose rows are in the user's data, on their way in or on their
     * way out: running, undoable, or being undone. What keeps the Settings
     * page and its history around once the user may no longer start one
     * (the window closed, or the master switch is off).
     *
     * @param  Builder<Import>  $query
     * @return Builder<Import>
     */
    public function scopeInUserData(Builder $query): Builder
    {
        return $query
            ->whereNull('undone_at')
            ->whereIn('status', [
                ImportStatus::Queued->value,
                ImportStatus::Processing->value,
                ImportStatus::Completed->value,
                ImportStatus::Failed->value,
                ImportStatus::Undoing->value,
            ]);
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
     * The next staged chunk of a kind still to be written, in the order the
     * browser cut the file into them. A chunk is deleted in the same database
     * transaction that writes its rows, so what is left is exactly what a
     * resumed run still has to do, and a decade of movements is never held in
     * memory at once.
     */
    public function nextChunk(ImportChunkKind $kind): ?ImportChunk
    {
        return $this->chunks()->where('kind', $kind->value)->orderBy('position')->first();
    }

    /**
     * What every log line about the import carries: ids and enums only, never
     * anything from the user's file.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function logContext(array $extra = []): array
    {
        return [
            'import_id' => $this->id,
            'user_id' => $this->user_id,
            'space_id' => $this->space_id,
            'source' => $this->source->value,
            'mode' => $this->mode->value,
            ...$extra,
        ];
    }

    /**
     * How a failure is kept for whoever investigates it: the exception class
     * and its message, cut to what is worth storing. Never shown to the user,
     * who gets a friendly sentence instead (ImportHistoryPresenter::status()).
     */
    public static function failureReason(?Throwable $exception): ?string
    {
        if ($exception === null) {
            return null;
        }

        return Str::limit($exception::class.': '.$exception->getMessage(), self::FAILURE_REASON_LENGTH, '…');
    }

    /** Well inside the `error` TEXT column, and enough for any exception message worth reading. */
    private const FAILURE_REASON_LENGTH = 2000;

    public function isUndoable(): bool
    {
        return $this->undone_at === null && $this->status->isFinished();
    }

    /**
     * Merge counts into the stats the progress screen polls, writing them at
     * once so a closed tab finds them on its next visit.
     *
     * The import job and the AI pass write to the same row from different
     * workers, so the merge happens on a locked, fresh read rather than on
     * whatever this instance loaded earlier: neither can wipe out the other's
     * progress.
     *
     * @param  array<string, mixed>  $stats
     */
    public function recordStats(array $stats): void
    {
        DB::transaction(function () use ($stats): void {
            $current = static::query()->whereKey($this->id)->lockForUpdate()->value('stats');
            $merged = array_replace_recursive(is_array($current) ? $current : [], $stats);

            static::query()->whereKey($this->id)->update(['stats' => json_encode($merged)]);

            $this->forceFill(['stats' => $merged])->syncOriginalAttribute('stats');
        });
    }

    /** Record the stage the import has reached. */
    public function recordStage(ImportStage $stage): void
    {
        $this->recordStats(['stage' => $stage->value]);
    }
}
