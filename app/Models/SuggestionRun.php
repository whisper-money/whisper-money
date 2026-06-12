<?php

namespace App\Models;

use App\Enums\SuggestionRunStatus;
use Database\Factories\SuggestionRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property SuggestionRunStatus $status
 */
class SuggestionRun extends Model
{
    /** @use HasFactory<SuggestionRunFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'status',
        'transactions_considered',
        'suggestions_count',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => SuggestionRunStatus::class,
            'transactions_considered' => 'integer',
            'suggestions_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<RuleSuggestion, $this> */
    public function suggestions(): HasMany
    {
        return $this->hasMany(RuleSuggestion::class);
    }
}
