<?php

namespace App\Models;

use App\Enums\IntegrationRequestStatus;
use Database\Factories\IntegrationRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $name
 * @property string $url
 * @property IntegrationRequestStatus $status
 * @property ?string $comment
 * @property string $user_id
 */
class IntegrationRequest extends Model
{
    /** @use HasFactory<IntegrationRequestFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'url',
        'status',
        'comment',
        'user_id',
    ];

    /** @var list<string> */
    protected $hidden = [
        'user_id',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => IntegrationRequestStatus::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<IntegrationRequestVote, $this> */
    public function votes(): HasMany
    {
        return $this->hasMany(IntegrationRequestVote::class);
    }
}
