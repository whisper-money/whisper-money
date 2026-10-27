import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { useStoredColumnVisibility } from './use-stored-column-visibility';

const KEY = 'transactions-column-visibility';

describe('useStoredColumnVisibility', () => {
    afterEach(() => localStorage.clear());

    it('starts from the defaults', () => {
        const { result } = renderHook(() => useStoredColumnVisibility());

        expect(result.current[0]).toMatchObject({
            account: true,
            notes: false,
        });
    });

    it('lays the saved choice over the defaults and saves changes', () => {
        localStorage.setItem(KEY, JSON.stringify({ notes: true }));
        const { result } = renderHook(() => useStoredColumnVisibility());

        expect(result.current[0]).toMatchObject({
            account: true,
            notes: true,
        });

        act(() =>
            result.current[1]((visibility) => ({
                ...visibility,
                account: false,
            })),
        );

        expect(JSON.parse(localStorage.getItem(KEY) ?? '{}')).toMatchObject({
            account: false,
            notes: true,
        });
    });
});
