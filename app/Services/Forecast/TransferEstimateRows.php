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
     * @param  string|null  $accountId  The account the amounts are signed from (a transfer into it is positive), or null to sign each from its destination.
     * @param  list<string>  $months  The `Y-m` months shown, the month in progress first.
     * @return list<array<string, mixed>>
     */
    public function rows(array $estimates, ?string $accountId, array $months): array
    {
        $shown = array_flip($months);
        $byPair = [];

        foreach ($estimates as $estimate) {
            if (isset($shown[$estimate->month]) && ($accountId === null || $estimate->touches($accountId))) {
                $byPair[$estimate->key()][] = $estimate;
            }
        }

        return array_values(array_map(fn (array $pair): array => $this->row($pair, $accountId ?? $pair[0]->toAccountId, $months[0]), $byPair));
    }

    /**
     * @param  non-empty-list<TransferEstimate>  $estimates  One pair's months.
     * @return array<string, mixed>
     */
    private function row(array $estimates, string $side, string $currentMonth): array
    {
        $first = $estimates[0];
        $incoming = $first->toAccountId === $side;
        $sign = $incoming ? 1 : -1;
        $amounts = [];
        $current = ['planned' => null, 'real' => null, 'date' => null, 'status' => 'none'];

        foreach ($estimates as $estimate) {
            $amounts[$estimate->month] = $sign * $estimate->amount;

            if ($estimate->month === $currentMonth) {
                $current = [
                    'planned' => $estimate->amount > 0 ? $sign * $estimate->amount : null,
                    'real' => $estimate->moved > 0 ? $sign * $estimate->moved : null,
                    'date' => $estimate->amount > 0 ? $estimate->date : null,
                    'status' => $estimate->amount > 0 ? 'pending' : 'matched',
                ];
            }
        }

        return [
            'id' => $first->key(),
            'name' => $first->toAccountName,
            'kind' => 'transfer',
            'source' => 'estimate',
            'anchorDate' => $first->date,
            'direction' => $incoming ? 'in' : 'out',
            'counterpartyName' => $incoming ? $first->fromAccountName : $first->toAccountName,
            'amounts' => $amounts,
            'current' => $current,
            'accountId' => $first->fromAccountId,
            'categoryId' => null,
            'definition' => [
                'id' => $first->key(),
                'accountId' => $first->fromAccountId,
                'destinationAccountId' => $first->toAccountId,
                'frequency' => 'monthly',
                'anchorDate' => $first->date,
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
