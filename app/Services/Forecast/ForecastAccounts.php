<?php

namespace App\Services\Forecast;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\ForecastAccountSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * The accounts a user's forecast in one space covers. What the user saved for
 * an account decides; without that, its type does: checking, savings and
 * credit card accounts are in, investment, retirement and other accounts are
 * in as projection only, and a loan or a property is left out (its monthly
 * payment is already an expense of the checking account it is paid from).
 *
 * A projection-only account counts toward the total, but never raises a
 * warning and never lends money to another one: only a liquid account
 * (checking or savings) does either.
 */
class ForecastAccounts
{
    /**
     * The account types the forecast leaves out unless the user includes them.
     *
     * @var list<AccountType>
     */
    private const EXCLUDED_BY_DEFAULT = [AccountType::Loan, AccountType::RealEstate];

    /**
     * Where the user's day-to-day money is: what the forecast warns about and
     * what a transfer is offered from.
     *
     * @var list<AccountType>
     */
    private const LIQUID = [AccountType::Checking, AccountType::Savings];

    /**
     * The account types projected for the day-to-day money: the liquid ones
     * and the credit card spent from. Every other type is projection only.
     *
     * @var list<AccountType>
     */
    private const SPENDING = [AccountType::Checking, AccountType::Savings, AccountType::CreditCard];

    private static function isIncludedByDefault(AccountType $type): bool
    {
        return ! in_array($type, self::EXCLUDED_BY_DEFAULT, true);
    }

    public static function isLiquid(AccountType $type): bool
    {
        return in_array($type, self::LIQUID, true);
    }

    public static function isProjectionOnly(AccountType $type): bool
    {
        return ! in_array($type, self::SPENDING, true);
    }

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
     * The spaces the user holds accounts in: each has a forecast of its own.
     *
     * @return SupportCollection<int, string>
     */
    public function spaceIds(User $user): SupportCollection
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->whereNotNull('space_id')
            ->distinct()
            ->pluck('space_id')
            ->values();
    }

    /**
     * @param  Collection<string, ForecastAccountSetting>|null  $settings  Read when not given.
     * @return Collection<int, Account> By name.
     */
    public function included(User $user, string $spaceId, ?Collection $settings = null): Collection
    {
        $settings ??= $this->settings($user, $spaceId);

        return $this->live($user, $spaceId)
            ->filter(fn (Account $account): bool => $this->isIncluded($account, $settings))
            ->values();
    }

    /**
     * Every live account of the user in the space, whether the forecast
     * covers it and whether as projection only: what the page lets the user
     * switch on and off.
     *
     * @return list<array{id: string, name: string, type: AccountType, included: bool, projectionOnly: bool}>
     */
    public function choices(User $user, string $spaceId): array
    {
        $settings = $this->settings($user, $spaceId);

        return $this->live($user, $spaceId)
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type,
                'included' => $this->isIncluded($account, $settings),
                'projectionOnly' => self::isProjectionOnly($account->type),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, ForecastAccountSetting>  $settings
     */
    private function isIncluded(Account $account, Collection $settings): bool
    {
        return $settings->has($account->id)
            ? $settings[$account->id]->included
            : self::isIncludedByDefault($account->type);
    }

    /**
     * @return Collection<int, Account> In the user's order, as the accounts page lists them.
     */
    private function live(User $user, string $spaceId): Collection
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->notArchived()
            ->orderBy('position')
            ->orderBy('name')
            ->get();
    }
}
