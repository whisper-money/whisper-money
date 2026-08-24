import { describe, expect, it } from 'vitest';

import { isNewSince } from './new-transactions';

const tx = (created_at: string) => ({ created_at });

describe('isNewSince', () => {
    it('is true when created after the last visit', () => {
        expect(
            isNewSince(tx('2026-06-29T10:00:00Z'), '2026-06-25T00:00:00Z'),
        ).toBe(true);
    });

    it('is false when created at or before the last visit', () => {
        expect(
            isNewSince(tx('2026-06-20T08:00:00Z'), '2026-06-25T00:00:00Z'),
        ).toBe(false);
    });

    it('does not rely on list order (a new row can be back-dated)', () => {
        // A row synced now but dated weeks ago is still "new".
        expect(
            isNewSince(tx('2026-06-29T10:00:00Z'), '2026-06-25T00:00:00Z'),
        ).toBe(true);
    });

    it('is false on a first visit (no stored timestamp)', () => {
        expect(isNewSince(tx('2026-06-29T10:00:00Z'), null)).toBe(false);
    });

    it('is false on an unparseable timestamp', () => {
        expect(isNewSince(tx('2026-06-29T10:00:00Z'), 'not-a-date')).toBe(
            false,
        );
    });
});
