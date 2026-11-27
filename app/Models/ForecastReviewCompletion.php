<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSpace;
use Database\Factories\ForecastReviewCompletionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * That a user answered the forecast's review in a space. Its first step only
 * shows the estimates, so answering it may add no line: this is what tells
 * the page not to open it on its own again. "Start over" deletes it.
 *
 * @property string $id
 * @property string $user_id
 * @property Carbon $completed_at
 */
class ForecastReviewCompletion extends Model
{
    /** @use HasFactory<ForecastReviewCompletionFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'completed_at',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
