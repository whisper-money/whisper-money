import { type SortingState, type VisibilityState } from '@tanstack/react-table';
import { act, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, it } from 'vitest';
import { useTransactionDayGrouping } from './use-transaction-day-grouping';

function renderGrouping(sorting: SortingState, saved: VisibilityState) {
    return renderHook(() => {
        const [columnVisibility, setColumnVisibility] =
            useState<VisibilityState>(saved);
        const grouping = useTransactionDayGrouping({
            sorting,
            columnVisibility,
            setColumnVisibility,
        });

        return { ...grouping, columnVisibility };
    });
}

describe('useTransactionDayGrouping', () => {
    it('hides the Date column from the table while grouped by day', () => {
        const { result } = renderGrouping(
            [{ id: 'transaction_date', desc: true }],
            { transaction_date: true, account: true },
        );

        expect(result.current.isGroupedByDay).toBe(true);
        expect(result.current.tableColumnVisibility).toEqual({
            transaction_date: false,
            account: true,
        });
    });

    it('hands the saved setting through untouched under any other sort', () => {
        const saved = { transaction_date: true, account: false };
        const { result } = renderGrouping(
            [{ id: 'amount', desc: true }],
            saved,
        );

        expect(result.current.isGroupedByDay).toBe(false);
        expect(result.current.tableColumnVisibility).toBe(saved);
    });

    it('never saves the forced-off Date while another column is toggled', () => {
        const { result } = renderGrouping(
            [{ id: 'transaction_date', desc: true }],
            { transaction_date: true, account: true },
        );

        act(() =>
            result.current.onColumnVisibilityChange((visibility) => ({
                ...visibility,
                account: false,
            })),
        );

        expect(result.current.columnVisibility).toEqual({
            transaction_date: true,
            account: false,
        });
    });

    it('leaves a Date setting the user never made unset', () => {
        const { result } = renderGrouping(
            [{ id: 'transaction_date', desc: true }],
            { account: true },
        );

        act(() =>
            result.current.onColumnVisibilityChange((visibility) => ({
                ...visibility,
                account: false,
            })),
        );

        expect(result.current.columnVisibility).toEqual({ account: false });
    });
});
