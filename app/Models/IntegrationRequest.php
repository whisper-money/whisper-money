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
 * @property-read int|null $voters_count Distinct voters, when loaded with `withCount(['votes as voters_count' => ...])`
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

    /**
     * Move the request to a new status. The comment is shown to users, so it
     * is only kept on the statuses that explain themselves with one.
     */
    public function review(IntegrationRequestStatus $status, ?string $comment = null): void
    {
        $this->update([
            'status' => $status,
            'comment' => $status->keepsComment() && filled($comment) ? $comment : null,
        ]);
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
