<?php

namespace App\Services\Forecast;

use App\Services\Forecast\Data\TransferEstimate;

/**
 * The usual transfers between the user's accounts (TransferEstimator) as
 * rows of the plan's transfers, in the shape of a line's row so the page
 * shows them next to the transfers the user made. They are read-only: no
 * month holds an occurrence of a line (`occurrenceDates` is empty), so no
 * cell opens a line's edit sheet; `source` is `estimate`.
 */
class TransferEstimateRows
{
    /**
     * @param  list<TransferEstimate>  $estimates
     * @param  string|null  $accountId  The account the amounts are signed from (a transfer into it is positive), or null for every account at once: each pair is then signed from its destination, or from its source when the destination is an account the forecast leaves out (a loan), so money leaving reads as leaving.
     * @param  list<string>  $months  The `Y-m` months shown, the month in progress first.
     * @param  list<string>  $projectedIds  The accounts the forecast projects.
     * @return list<array<string, mixed>>
     */
    public function rows(array $estimates, ?string $accountId, array $months, array $projectedIds): array
    {
        $shown = array_flip($months);
        $projected = array_flip($projectedIds);
        $byPair = [];

        foreach ($estimates as $estimate) {
            if (isset($shown[$estimate->month]) && ($accountId === null || $estimate->touches($accountId))) {
                $byPair[$estimate->key()][] = $estimate;
            }
        }

        return array_values(array_map(
            fn (array $pair): array => $this->row($pair, $accountId ?? (isset($projected[$pair[0]->toAccountId]) ? $pair[0]->toAccountId : $pair[0]->fromAccountId), $months[0], $accountId === null),
            $byPair,
        ));
    }

    /**
     * One pair's row. `name` is the destination's name and
     * `counterpartyName` the other account's, seen from `$side` (the
     * source's, when every account is shown), as for a transfer line. In the month in progress, `planned` is the month's
     * whole figure and `real` what already moved, the same as a line's;
     * its `anchorDate` is the usual day, read from a month after the
     * start, which clamps it to the days left.
     *
     * @param  non-empty-list<TransferEstimate>  $estimates  One pair's months, the earliest first.
     * @return array<string, mixed>
     */
    private function row(array $estimates, string $side, string $currentMonth, bool $allAccounts): array
    {
        $first = $estimates[0];
        $usual = $estimates[1] ?? $first;
        $incoming = $first->toAccountId === $side;
        $sign = $incoming ? 1 : -1;
        $amounts = [];
        $current = ['planned' => null, 'real' => null, 'date' => null, 'status' => 'none'];

        foreach ($estimates as $estimate) {
            $amounts[$estimate->month] = $sign * ($estimate->month === $currentMonth ? $estimate->planned : $estimate->amount);

            if ($estimate->month === $currentMonth) {
                $current = [
                    'planned' => $sign * $estimate->planned,
                    'real' => $estimate->moved > 0 ? $sign * $estimate->moved : null,
                    'date' => $estimate->date,
                    'status' => $estimate->amount > 0 ? 'pending' : 'matched',
                ];
            }
        }

        return [
            'id' => $first->key(),
            'name' => $first->toAccountName,
            'kind' => 'transfer',
            'source' => 'estimate',
            'anchorDate' => $usual->date,
            'direction' => $incoming ? 'in' : 'out',
            'counterpartyName' => $incoming || $allAccounts ? $first->fromAccountName : $first->toAccountName,
            'amounts' => $amounts,
            'current' => $current,
            'accountId' => $first->fromAccountId,
            'categoryId' => null,
            'definition' => [
                'id' => $first->key(),
                'accountId' => $first->fromAccountId,
                'destinationAccountId' => $first->toAccountId,
                'frequency' => 'monthly',
                'anchorDate' => $usual->date,
                'endsOn' => null,
                'versions' => [],
                'overrides' => [],
            ],
            'occurrenceDates' => [],
            'matchText' => null,
            'amountTolerance' => 0,
            'dateWindowDays' => 0,
            'payments' => [],
            'drift' => null,
        ];
    }
}
