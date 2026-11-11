<?php

namespace App\Models;

use Database\Factories\DashboardFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A dashboard the user put together from widgets. The system dashboard every
 * user starts with is not one of these: it is what `users.default_dashboard_id`
 * being null means.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property int $position
 */
class Dashboard extends Model
{
    /** @use HasFactory<DashboardFactory> */
    use HasFactory, HasUuids;

    /** How many dashboards a user may keep. */
    public const MAX_PER_USER = 20;

    /** How many widgets one dashboard may hold. */
    public const MAX_WIDGETS = 50;

    protected $fillable = [
        'user_id',
        'name',
        'position',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<DashboardWidget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class);
    }
}
