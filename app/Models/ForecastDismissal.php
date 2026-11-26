<?php

namespace App\Models;

use App\Enums\ForecastSuggestionKind;
use App\Models\Concerns\BelongsToSpace;
use Database\Factories\ForecastDismissalFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A forecast suggestion the user turned down, remembered by its description
 * fingerprint so it is not proposed again.
 *
 * @property string $id
 * @property string $user_id
 * @property string $fingerprint
 * @property ForecastSuggestionKind $kind
 */
class ForecastDismissal extends Model
{
    /** @use HasFactory<ForecastDismissalFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'fingerprint',
        'kind',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ForecastSuggestionKind::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
