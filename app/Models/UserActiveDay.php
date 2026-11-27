<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day a user opened the app, written by `TrackLastActiveAt`.
 */
class UserActiveDay extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $fillable = ['user_id', 'date'];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * Records that the user was active today, a UTC day. Idempotent: a second call on the
     * same day changes nothing.
     */
    public static function record(User $user): void
    {
        static::query()->insertOrIgnore([
            'user_id' => $user->id,
            'date' => now('UTC')->toDateString(),
        ]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
