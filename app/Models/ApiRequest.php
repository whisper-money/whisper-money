<?php

namespace App\Models;

use App\Enums\ApiCredential;
use Database\Factories\ApiRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single authenticated `/api/v1` request, recorded by RecordApiRequest.
 * `endpoint` is the route name; nothing from the request body is stored.
 */
class ApiRequest extends Model
{
    /** @use HasFactory<ApiRequestFactory> */
    use HasFactory, HasUuids, MassPrunable;

    /** Append-only: a request is never updated, so there is no `updated_at`. */
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'method',
        'endpoint',
        'credential',
        'oauth_client_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'credential' => ApiCredential::class,
            'status' => 'integer',
        ];
    }

    /**
     * Rows are kept 90 days: `stats:api-usage` looks back 30 by default, and
     * a quarter leaves room to compare a month with the ones before it.
     *
     * @return Builder<ApiRequest>
     */
    public function prunable(): Builder
    {
        return ApiRequest::query()->where('created_at', '<', now()->subDays(90));
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
