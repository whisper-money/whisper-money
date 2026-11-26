<?php

namespace App\Services\Forecast;

use App\Features\Forecast;
use App\Models\ForecastItem;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Laravel\Pennant\Feature;

/**
 * Who the forecast's background work is for: users with the `Forecast` flag
 * on. Everyone else is left alone, so the listeners, jobs and the refresh
 * command do nothing for them and store nothing.
 */
class ForecastAudience
{
    public function isActive(User $user): bool
    {
        return Feature::for($user)->active(Forecast::class);
    }

    /**
     * Whether a user's transactions are worth matching: they have a line to
     * match against and the flag on. The lines are checked first: it is the
     * cheaper question, and checking the flag first would store a resolved
     * value for every user whose transactions move.
     */
    public function matchesTransactionsOf(User $user): bool
    {
        return $this->hasLines($user->id) && $this->isActive($user);
    }

    /**
     * Like matchesTransactionsOf(), from a transaction, loading its owner only
     * when they have a line: it runs on every transaction write.
     */
    public function matchesTransaction(Transaction $transaction): bool
    {
        return $this->hasLines($transaction->user_id)
            && $transaction->user !== null
            && $this->isActive($transaction->user);
    }

    private function hasLines(string $userId): bool
    {
        return ForecastItem::query()->where('user_id', $userId)->notArchived()->exists();
    }

    /**
     * The users the flag is on for. Pennant has no query for that, so the
     * stored values are read directly (a flag is only ever on through a
     * stored value: it resolves off), and each user is checked again through
     * Pennant before being returned.
     *
     * @return LazyCollection<int, User>
     */
    public function users(): LazyCollection
    {
        $store = config('pennant.stores.database');
        $prefix = User::class.'|';

        $userIds = DB::connection($store['connection'] ?? null)
            ->table($store['table'] ?? 'features')
            ->where('name', Forecast::class)
            ->where('value', 'true')
            ->pluck('scope')
            ->filter(fn (string $scope): bool => str_starts_with($scope, $prefix))
            ->map(fn (string $scope): string => substr($scope, strlen($prefix)))
            ->values();

        return User::query()
            ->whereIn('id', $userIds)
            ->lazyById()
            ->filter(fn (User $user): bool => $this->isActive($user));
    }
}
