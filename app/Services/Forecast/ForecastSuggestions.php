<?php

namespace App\Services\Forecast;

use App\Models\ForecastDismissal;
use App\Models\ForecastItem;
use App\Models\User;
use App\Services\Forecast\Data\ForecastCandidate;

/**
 * The yearly payments the forecast proposes to a user in one space, for the
 * second step of the review, read from the last 18 months of the accounts
 * the forecast includes. A suggestion the user dismissed, or one an
 * existing line of the same account already covers by fingerprint, is not
 * proposed again. Nothing is stored.
 */
class ForecastSuggestions
{
    private const HISTORY_MONTHS = 18;

    public function __construct(
        private readonly ForecastHistory $history,
        private readonly AnnualSuggester $annual,
        private readonly TransferPairer $pairer,
        private readonly DescriptionFingerprint $fingerprint,
        private readonly ForecastAccounts $accounts,
    ) {}

    /**
     * @return list<ForecastCandidate>
     */
    public function for(User $user, string $spaceId, ?string $today = null): array
    {
        $today ??= $user->localToday()->toDateString();
        $accountIds = $this->accounts->included($user, $spaceId)->modelKeys();
        $transactions = $this->history->transactions($user, $spaceId, ForecastCalendar::monthStart($today, -self::HISTORY_MONTHS), $today, $accountIds);
        $legs = $this->pairer->pair($transactions);
        $groups = $this->fingerprint->groups($transactions);
        $known = $this->knownKeys($user, $spaceId);

        $isNew = fn (ForecastCandidate $candidate): bool => ! isset($known['dismissed'][$candidate->kind->value.'|'.$candidate->identity()])
            && ! isset($known['planned'][$candidate->accountId.'|'.$candidate->identity()]);

        return array_values(array_filter($this->annual->suggest($groups, $legs, $today), $isNew));
    }

    /**
     * @return array{dismissed: array<string, true>, planned: array<string, true>}
     */
    private function knownKeys(User $user, string $spaceId): array
    {
        $dismissed = ForecastDismissal::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->get(['kind', 'fingerprint'])
            ->mapWithKeys(fn (ForecastDismissal $dismissal): array => [$dismissal->kind->value.'|'.$dismissal->fingerprint => true]);

        $planned = ForecastItem::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->notArchived()
            ->whereNotNull('fingerprint')
            ->get(['account_id', 'fingerprint'])
            ->mapWithKeys(fn (ForecastItem $item): array => [$item->account_id.'|'.$item->fingerprint => true]);

        return ['dismissed' => $dismissed->all(), 'planned' => $planned->all()];
    }
}
