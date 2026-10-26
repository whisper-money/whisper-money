import { isSortedByDate } from '@/lib/transaction-day-groups';
import {
    type OnChangeFn,
    type SortingState,
    type VisibilityState,
} from '@tanstack/react-table';
import {
    type Dispatch,
    type SetStateAction,
    useCallback,
    useMemo,
} from 'react';

/**
 * Day headers for a transactions table sorted by date.
 *
 * While grouped, the headers carry the date, so the Date column steps aside.
 * It does so in what the table is handed, never in the saved setting: sorting
 * by amount brings the column back exactly as the user left it, and a column
 * toggled meanwhile does not write the forced-off Date into storage.
 */
export function useTransactionDayGrouping({
    sorting,
    columnVisibility,
    setColumnVisibility,
}: {
    sorting: SortingState;
    columnVisibility: VisibilityState;
    setColumnVisibility: Dispatch<SetStateAction<VisibilityState>>;
}): {
    isGroupedByDay: boolean;
    tableColumnVisibility: VisibilityState;
    onColumnVisibilityChange: OnChangeFn<VisibilityState>;
} {
    const isGroupedByDay = isSortedByDate(sorting);

    const tableColumnVisibility = useMemo(
        () =>
            isGroupedByDay
                ? { ...columnVisibility, transaction_date: false }
                : columnVisibility,
        [columnVisibility, isGroupedByDay],
    );

    const onColumnVisibilityChange = useCallback<OnChangeFn<VisibilityState>>(
        (updater) =>
            setColumnVisibility((saved) => {
                if (!isGroupedByDay) {
                    return typeof updater === 'function'
                        ? updater(saved)
                        : updater;
                }

                const shown = { ...saved, transaction_date: false };
                const next =
                    typeof updater === 'function' ? updater(shown) : updater;
                const restored = { ...next };
                delete restored.transaction_date;

                if ('transaction_date' in saved) {
                    restored.transaction_date = saved.transaction_date;
                }

                return restored;
            }),
        [isGroupedByDay, setColumnVisibility],
    );

    return { isGroupedByDay, tableColumnVisibility, onColumnVisibilityChange };
}
