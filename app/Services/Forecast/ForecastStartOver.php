<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\ForecastAccountSetting;
use App\Models\ForecastAlert;
use App\Models\ForecastDismissal;
use App\Models\ForecastEstimateAdjustment;
use App\Models\ForecastExcludedTransaction;
use App\Models\ForecastItem;
use App\Models\ForecastItemOverride;
use App\Models\ForecastItemVersion;
use App\Models\ForecastMatch;
use App\Models\ForecastSnapshot;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\ForecastAlertRaised;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * "Start over": forgets everything the user built in one space's forecast, so
 * it is worked out again from their transactions and the review opens as for
 * a new user. Only that user's rows in that space go, and they go for good
 * (lines are force-deleted, with their versions, overrides and matches):
 * another member of a shared space, or the user's other spaces, keep theirs.
 *
 * A bell row about a short month names the alerts it was rung for. It goes
 * when every alert it names was in this space. A row from before rows named
 * their alerts goes only when the user has no alert in another space, since
 * it cannot tell which space it was about.
 */
class ForecastStartOver
{
    public function __construct(private readonly ForecastSuggestionCount $count) {}

    public function startOver(User $user, string $spaceId): void
    {
        DB::transaction(function () use ($user, $spaceId): void {
            $this->deleteLines($user, $spaceId);
            $this->deleteAlerts($user, $spaceId);

            ForecastExcludedTransaction::query()
                ->whereIn('transaction_id', Transaction::withTrashed()->select('id')->where('user_id', $user->id)->where('space_id', $spaceId))
                ->delete();
            ForecastAccountSetting::query()
                ->whereIn('account_id', Account::withTrashed()->select('id')->where('user_id', $user->id)->where('space_id', $spaceId))
                ->delete();

            foreach ([ForecastEstimateAdjustment::class, ForecastDismissal::class, ForecastSnapshot::class] as $model) {
                $model::query()->where('user_id', $user->id)->forSpace($spaceId)->delete();
            }
        });

        $this->count->forget($user, $spaceId);
    }

    private function deleteLines(User $user, string $spaceId): void
    {
        $items = ForecastItem::withTrashed()->select('id')->where('user_id', $user->id)->forSpace($spaceId);

        foreach ([ForecastMatch::class, ForecastItemOverride::class, ForecastItemVersion::class] as $model) {
            $model::query()->whereIn('forecast_item_id', $items)->delete();
        }

        ForecastItem::withTrashed()->where('user_id', $user->id)->forSpace($spaceId)->forceDelete();
    }

    private function deleteAlerts(User $user, string $spaceId): void
    {
        $alertIds = ForecastAlert::query()->where('user_id', $user->id)->forSpace($spaceId)->pluck('id')->all();
        $hasAlertsElsewhere = ForecastAlert::query()->where('user_id', $user->id)->where('space_id', '!=', $spaceId)->exists();

        $rung = $user->notifications()->where('type', ForecastAlertRaised::class)->get()
            ->filter(fn (DatabaseNotification $notification): bool => self::wasAboutOnly($notification, $alertIds, $hasAlertsElsewhere));

        DatabaseNotification::query()->whereKey($rung->modelKeys())->delete();
        ForecastAlert::query()->whereKey($alertIds)->delete();
    }

    /**
     * @param  list<string>  $alertIds
     */
    private static function wasAboutOnly(DatabaseNotification $notification, array $alertIds, bool $hasAlertsElsewhere): bool
    {
        $named = $notification->data['alerts'] ?? null;

        if (! is_array($named) || $named === []) {
            return ! $hasAlertsElsewhere;
        }

        return array_diff($named, $alertIds) === [];
    }
}
