<?php

namespace App\Models;

use App\Enums\ChartColorScheme;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property \App\Enums\ChartColorScheme $chart_color_scheme
 * @property bool $include_loans_in_net_worth_chart
 * @property bool $include_real_estate_in_net_worth_chart
 */
class UserSetting extends Model
{
    /** @use HasFactory<\Database\Factories\UserSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'chart_color_scheme',
        'include_loans_in_net_worth_chart',
        'include_real_estate_in_net_worth_chart',
    ];

    protected function casts(): array
    {
        return [
            'chart_color_scheme' => ChartColorScheme::class,
            'include_loans_in_net_worth_chart' => 'boolean',
            'include_real_estate_in_net_worth_chart' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
