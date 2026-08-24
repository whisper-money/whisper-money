<?php

namespace App\Models;

use Database\Factories\IntegrationRequestVoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $integration_request_id
 * @property string $user_id
 */
class IntegrationRequestVote extends Model
{
    /** @use HasFactory<IntegrationRequestVoteFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'integration_request_id',
        'user_id',
    ];

    /** @return BelongsTo<IntegrationRequest, $this> */
    public function integrationRequest(): BelongsTo
    {
        return $this->belongsTo(IntegrationRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
