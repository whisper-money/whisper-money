<?php

namespace App\Services\Forecast;

use App\Models\Account;
use App\Models\ForecastAccountSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The accounts a user's forecast in one space covers: every live account of
 * theirs there, unless they left it out of the forecast.
 */
class ForecastAccounts
{
    /**
     * @return Collection<string, ForecastAccountSetting> By account id.
     */
    public function settings(User $user, string $spaceId): Collection
    {
        return ForecastAccountSetting::query()
            ->whereIn('account_id', Account::query()->where('user_id', $user->id)->forSpace($spaceId)->select('id'))
            ->get()
            ->keyBy('account_id');
    }

    /**
     * @param  Collection<string, ForecastAccountSetting>|null  $settings  Read when not given.
     * @return Collection<int, Account> By name.
     */
    public function included(User $user, string $spaceId, ?Collection $settings = null): Collection
    {
        $settings ??= $this->settings($user, $spaceId);

        return Account::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->notArchived()
            ->orderBy('name')
            ->get()
            ->filter(fn (Account $account): bool => ! $settings->has($account->id) || $settings[$account->id]->included)
            ->values();
    }
}
