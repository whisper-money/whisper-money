import { estimates as estimatesRoute } from '@/actions/App/Http/Controllers/Api/TransactionController';
import { transactionSyncService } from '@/services/transaction-sync';
import axios from 'axios';
import { useCallback, useEffect, useMemo, useState } from 'react';

/** An "Estimate" transaction already on the account, as the API lists it. */
export interface OverlappingEstimate {
    id: string;
    description: string;
    transaction_date: string;
    amount: number;
    currency_code: string;
}

/** The calendar months (`YYYY-MM`) the given dates fall in, oldest first. */
export function monthsOf(dates: string[]): string[] {
    return [
        ...new Set(
            dates
                .filter((date) => date.length >= 7)
                .map((date) => date.slice(0, 7)),
        ),
    ].sort();
}

/**
 * The estimates typed in during onboarding that sit in a month a file about to
 * be imported also covers: once the real movements are in, the month would be
 * counted twice. `remove` deletes them all, through the same path a delete
 * from the transactions list takes, and without moving the balance — they
 * never moved it.
 *
 * A failed lookup shows no warning at all: it must not hold up an import.
 */
export function useOverlappingEstimates(
    accountId: string | null | undefined,
    dates: string[],
) {
    const [estimates, setEstimates] = useState<OverlappingEstimate[]>([]);
    const [isRemoving, setIsRemoving] = useState(false);
    const [removedCount, setRemovedCount] = useState(0);
    const [removeFailed, setRemoveFailed] = useState(false);
    const months = useMemo(() => monthsOf(dates), [dates]);
    const monthsKey = months.join(',');

    useEffect(() => {
        // Another file or another account is another question.
        setRemovedCount(0);
        setRemoveFailed(false);

        if (!accountId || monthsKey === '') {
            setEstimates([]);
            return;
        }

        let cancelled = false;

        axios
            .get<{ data: OverlappingEstimate[] }>(
                estimatesRoute.url({
                    query: {
                        account_id: accountId,
                        months: monthsKey.split(','),
                    },
                }),
            )
            .then(({ data }) => {
                if (!cancelled) {
                    setEstimates(data.data ?? []);
                }
            })
            .catch(() => {
                // Only a warning: an import must not be held up by it.
                if (!cancelled) {
                    setEstimates([]);
                }
            });

        return () => {
            cancelled = true;
        };
    }, [accountId, monthsKey]);

    /**
     * One at a time, so a failure halfway leaves the notice listing exactly
     * the ones still there rather than all of them.
     */
    const remove = useCallback(async () => {
        setIsRemoving(true);
        setRemoveFailed(false);

        let removed = 0;

        try {
            for (const estimate of estimates) {
                await transactionSyncService.delete(estimate.id);
                removed++;
            }
        } catch {
            setRemoveFailed(true);
        } finally {
            setRemovedCount((previous) => previous + removed);
            setEstimates((previous) => previous.slice(removed));
            setIsRemoving(false);
        }
    }, [estimates]);

    return { estimates, remove, isRemoving, removedCount, removeFailed };
}
