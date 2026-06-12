<?php

namespace App\Models;

use Database\Factories\AiConsentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConsent extends Model
{
    /** @use HasFactory<AiConsentFactory> */
    use HasFactory, HasUuids;

    /**
     * The broad "use AI to help understand your finances" consent scope.
     */
    public const SCOPE_FINANCE = 'finance';

    protected $fillable = [
        'user_id',
        'scope',
        'version',
        'accepted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to consents that are currently active for the given scope and the
     * current consent version (un-revoked and matching the live copy version).
     *
     * @param  Builder<AiConsent>  $query
     * @return Builder<AiConsent>
     */
    public function scopeActive(Builder $query, string $scope = self::SCOPE_FINANCE): Builder
    {
        return $query
            ->where('scope', $scope)
            ->where('version', (string) config('ai_suggestions.consent_version'))
            ->whereNull('revoked_at');
    }
}
