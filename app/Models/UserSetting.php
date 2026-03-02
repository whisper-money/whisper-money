<?php

namespace App\Models;

use App\Enums\ChartColorScheme;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \App\Enums\ChartColorScheme $chart_color_scheme
 */
class UserSetting extends Model
{
    /** @use HasFactory<\Database\Factories\UserSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'chart_color_scheme',
    ];

    protected function casts(): array
    {
        return [
            'chart_color_scheme' => ChartColorScheme::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
