import { type VisibilityState } from '@tanstack/react-table';
import { type Dispatch, type SetStateAction, useEffect, useState } from 'react';

const COLUMN_VISIBILITY_KEY = 'transactions-column-visibility';

const DEFAULT_COLUMN_VISIBILITY: VisibilityState = {
    transaction_date: true,
    account: true,
    creditor_name: false,
    debtor_name: false,
    labels: true,
    notes: false,
};

function readColumnVisibility(): VisibilityState {
    if (typeof window === 'undefined') {
        return DEFAULT_COLUMN_VISIBILITY;
    }

    try {
        const stored = localStorage.getItem(COLUMN_VISIBILITY_KEY);
        if (stored) {
            return { ...DEFAULT_COLUMN_VISIBILITY, ...JSON.parse(stored) };
        }
    } catch (error) {
        console.error(
            'Failed to load column visibility from localStorage:',
            error,
        );
    }
    return DEFAULT_COLUMN_VISIBILITY;
}

/**
 * The columns the user chose to show, one setting for every transactions
 * table, kept in localStorage.
 */
export function useStoredColumnVisibility(): [
    VisibilityState,
    Dispatch<SetStateAction<VisibilityState>>,
] {
    const [columnVisibility, setColumnVisibility] =
        useState<VisibilityState>(readColumnVisibility);

    useEffect(() => {
        try {
            localStorage.setItem(
                COLUMN_VISIBILITY_KEY,
                JSON.stringify(columnVisibility),
            );
        } catch (error) {
            console.error(
                'Failed to save column visibility to localStorage:',
                error,
            );
        }
    }, [columnVisibility]);

    return [columnVisibility, setColumnVisibility];
}
