<?php

namespace App\Models;

use App\Enums\ApiCredential;
use Database\Factories\ApiRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single authenticated `/api/v1` request, recorded by RecordApiRequest.
 * `endpoint` is the route name; nothing from the request body is stored.
 */
class ApiRequest extends Model
{
    /** @use HasFactory<ApiRequestFactory> */
    use HasFactory, HasUuids;

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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
