<?php

namespace App\Models;

use App\Enums\ChartColorScheme;
use Database\Factories\UserSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ChartColorScheme $chart_color_scheme
 * @property bool $include_loans_in_net_worth_chart
 * @property bool $include_real_estate_in_net_worth_chart
 * @property bool $notify_on_bank_transactions_synced
 * @property bool $budget_notify_on_new_transaction
 * @property bool $budget_notify_on_close_to_limit
 * @property bool $budget_notify_on_over_limit
 */
class UserSetting extends Model
{
    /** @use HasFactory<UserSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'chart_color_scheme',
        'include_loans_in_net_worth_chart',
        'include_real_estate_in_net_worth_chart',
        'notify_on_bank_transactions_synced',
        'budget_notify_on_new_transaction',
        'budget_notify_on_close_to_limit',
        'budget_notify_on_over_limit',
    ];

    protected function casts(): array
    {
        return [
            'chart_color_scheme' => ChartColorScheme::class,
            'include_loans_in_net_worth_chart' => 'boolean',
            'include_real_estate_in_net_worth_chart' => 'boolean',
            'notify_on_bank_transactions_synced' => 'boolean',
            'budget_notify_on_new_transaction' => 'boolean',
            'budget_notify_on_close_to_limit' => 'boolean',
            'budget_notify_on_over_limit' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
