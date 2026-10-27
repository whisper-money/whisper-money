import { type TransactionFilters } from '@/types/transaction';
import { useEffect, useRef, useState } from 'react';

type TextFilter = 'searchText' | 'creditorName' | 'debtorName';

const TEXT_FILTERS: TextFilter[] = ['searchText', 'creditorName', 'debtorName'];

const DEBOUNCE_MS = 300;

export type TextFilterValues = Record<TextFilter, string>;

function textValuesOf(filters: TransactionFilters): TextFilterValues {
    return {
        searchText: filters.searchText,
        creditorName: filters.creditorName,
        debtorName: filters.debtorName,
    };
}

/**
 * The typed filters (search and counterparties) as the fields show them,
 * applied 300ms after the last keystroke so every letter does not ask the
 * server again. A change from outside (Clear, a saved filter, the URL)
 * replaces what the fields show.
 */
export function useDebouncedTextFilters(
    filters: TransactionFilters,
    onFiltersChange: (filters: TransactionFilters) => void,
): {
    values: TextFilterValues;
    setValue: (filter: TextFilter, value: string) => void;
    /** Empties the fields, dropping what is typed but not yet applied. */
    clear: () => void;
} {
    const [values, setValues] = useState(() => textValuesOf(filters));
    // The debounced push reads the filters as they are when it fires, so a
    // filter picked while typing is not undone by it.
    const latest = useRef({ filters, onFiltersChange });

    useEffect(() => {
        latest.current = { filters, onFiltersChange };
    });

    useEffect(() => {
        const timer = setTimeout(() => {
            const { filters: current, onFiltersChange: apply } = latest.current;
            if (TEXT_FILTERS.some((key) => values[key] !== current[key])) {
                apply({ ...current, ...values });
            }
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [values]);

    // The text last seen in the filters. When it moves, a change came from
    // outside (or our own push landed) and the fields follow it; comparing
    // against it rather than against the fields keeps a keystroke made since
    // the last push from being overwritten on every render.
    const [applied, setApplied] = useState(() => textValuesOf(filters));
    const incoming = textValuesOf(filters);
    if (TEXT_FILTERS.some((key) => incoming[key] !== applied[key])) {
        setApplied(incoming);
        setValues(incoming);
    }

    return {
        values,
        setValue: (filter, value) =>
            setValues((current) => ({ ...current, [filter]: value })),
        clear: () =>
            setValues({ searchText: '', creditorName: '', debtorName: '' }),
    };
}
