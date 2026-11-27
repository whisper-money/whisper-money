<?php

namespace App\Models;

use App\Enums\ForecastConfidence;
use App\Enums\ForecastFrequency;
use App\Enums\ForecastItemKind;
use App\Enums\ForecastItemSource;
use App\Models\Concerns\Archivable;
use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\ForecastItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A line of the cash-flow forecast: a recurring charge or income, a one-off,
 * or a transfer. A transfer names its destination account, or is marked as
 * leaving the app (`destination_external`), in which case it counts as spend.
 *
 * The amount is never stored here: it is the version in force on each
 * occurrence, and single occurrences can be overridden. OccurrenceExpander
 * turns the three into dated occurrences.
 *
 * @property string $id
 * @property string $user_id
 * @property string $account_id
 * @property string|null $destination_account_id
 * @property bool $destination_external
 * @property string|null $category_id
 * @property string $name
 * @property ForecastItemKind $kind
 * @property ForecastFrequency $frequency
 * @property Carbon $anchor_date
 * @property Carbon|null $ends_on
 * @property ForecastItemSource $source
 * @property ForecastConfidence $confidence
 * @property array<string, mixed>|null $match_rules_json
 * @property int $date_window_days
 * @property int $amount_tolerance
 * @property string|null $fingerprint
 * @property Carbon|null $archived_at
 */
class ForecastItem extends Model
{
    /** @use HasFactory<ForecastItemFactory> */
    use Archivable, BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'account_id',
        'destination_account_id',
        'destination_external',
        'category_id',
        'name',
        'kind',
        'frequency',
        'anchor_date',
        'ends_on',
        'source',
        'confidence',
        'match_rules_json',
        'date_window_days',
        'amount_tolerance',
        'fingerprint',
        'archived_at',
    ];

    /**
     * Mirrors the column defaults, so a line that was just created reads the
     * same before and after a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'destination_external' => false,
        'date_window_days' => 3,
        'amount_tolerance' => 0,
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'destination_external' => 'boolean',
            'kind' => ForecastItemKind::class,
            'frequency' => ForecastFrequency::class,
            'anchor_date' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'source' => ForecastItemSource::class,
            'confidence' => ForecastConfidence::class,
            'match_rules_json' => 'array',
            'date_window_days' => 'integer',
            'amount_tolerance' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The lines the forecast projects: live ones the user made or kept by
     * hand (yearly, one-off, edited). Detected recurring lines stay stored
     * but no longer project: each category's estimate covers them.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeProjecting(Builder $query): Builder
    {
        return $query->notArchived()->where('source', '!=', ForecastItemSource::Detected);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'destination_account_id');
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ForecastItemVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ForecastItemVersion::class)->orderBy('effective_from');
    }

    /** @return HasMany<ForecastItemOverride, $this> */
    public function overrides(): HasMany
    {
        return $this->hasMany(ForecastItemOverride::class);
    }

    /** @return HasMany<ForecastMatch, $this> */
    public function matches(): HasMany
    {
        return $this->hasMany(ForecastMatch::class);
    }

    /**
     * Whether each occurrence is paid by several transactions: a line the
     * review merged from several suggestions (three subscriptions as one
     * line), whose rule recognises any of them, so its rule is an `or` at the
     * top. Each of those transactions pays a part of the occurrence.
     */
    public function takesSeveralPayments(): bool
    {
        return is_array($this->match_rules_json) && array_key_exists('or', $this->match_rules_json);
    }

    /**
     * Whether the line moves money between two accounts, or out of the app.
     */
    public function isTransfer(): bool
    {
        return $this->destination_account_id !== null || $this->destination_external;
    }
}
