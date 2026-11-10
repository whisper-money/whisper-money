<?php

namespace App\Mail\Drip;

use App\Mail\Concerns\MarketingUnsubscribe;
use App\Models\User;
use App\Support\Figures;

/**
 * "While you were away": sent to a Pro user with a bank connected who has not
 * opened the app in two weeks. It leads with what synced in the meantime, or,
 * when nothing did, with the broken connection that explains it.
 *
 * Counts and a percentage, never an amount — the same line the monthly summary
 * email holds.
 */
class InactiveBankedEmail extends DripMail
{
    use MarketingUnsubscribe;

    /**
     * @param  array{newTransactions: int, uncategorized: int, spendingChange: ?float}  $recap
     */
    public function __construct(User $user, public array $recap)
    {
        parent::__construct($user);
    }

    protected function dripSubject(): string
    {
        if (! $this->hasNewTransactions()) {
            return __('Nothing has synced from your bank in two weeks');
        }

        return $this->countLine(
            '{1} 1 new transaction came in while you were away|[2,*] :count new transactions came in while you were away',
            $this->recap['newTransactions'],
            app()->getLocale(),
        );
    }

    protected function template(): string
    {
        return 'mail.drip.inactive-banked';
    }

    protected function repliesToSender(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentData(): array
    {
        $locale = app()->getLocale();

        return [
            'hasNewTransactions' => $this->hasNewTransactions(),
            'newTransactionsLine' => $this->countLine(
                '{1} **1 new transaction** came in from your bank.|[2,*] **:count new transactions** came in from your bank.',
                $this->recap['newTransactions'],
                $locale,
            ),
            'uncategorizedLine' => $this->recap['uncategorized'] > 0
                ? $this->countLine(
                    '{1} **1** of them still needs a category.|[2,*] **:count** of them still need a category.',
                    $this->recap['uncategorized'],
                    $locale,
                )
                : __('All of them are already categorized.'),
            'spendingLine' => $this->spendingLine($locale),
        ];
    }

    private function countLine(string $key, int $count, string $locale): string
    {
        return trans_choice($key, $count, ['count' => Figures::count($count, $locale)]);
    }

    private function hasNewTransactions(): bool
    {
        return $this->recap['newTransactions'] > 0;
    }

    private function spendingLine(string $locale): ?string
    {
        $change = $this->recap['spendingChange'];

        if ($change === null) {
            return null;
        }

        if (abs($change) < 1) {
            return __('So far this month you have spent about the same as by this point last month.');
        }

        $rate = Figures::percent(abs($change), $locale, decimals: 0);

        return $change > 0
            ? __('So far this month you have spent :rate more than by this point last month.', ['rate' => $rate])
            : __('So far this month you have spent :rate less than by this point last month.', ['rate' => $rate]);
    }
}
