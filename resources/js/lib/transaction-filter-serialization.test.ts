import { type TransactionFilters } from '@/types/transaction';
import { formatLocalDate, toLocalDate } from '@/utils/date';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import {
    deserializeFilters,
    serializeFilters,
} from './transaction-filter-serialization';

const NO_FILTERS: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
    aiCategorizedOnly: false,
};

/**
 * The date filter reads and writes the same value twice, and the two halves
 * used to disagree: the visible input showed the day before while the request
 * carried the right one, so fixing either half alone broke the other. They are
 * asserted together here for exactly that reason.
 */
describe.each([
    'America/Argentina/Buenos_Aires',
    'Europe/Madrid',
    'Pacific/Auckland',
])('the date filter round trip in %s', (timeZone) => {
    const originalTimeZone = process.env.TZ;

    beforeAll(() => {
        process.env.TZ = timeZone;
    });

    afterAll(() => {
        process.env.TZ = originalTimeZone;
    });

    it('keeps the picked day in both the input and the serialized filter', () => {
        // What `<Input type="date">` hands its onChange when the user picks 9 Sept.
        const picked = toLocalDate('2026-09-09');
        const filters: TransactionFilters = {
            ...NO_FILTERS,
            dateFrom: picked,
            dateTo: picked,
        };

        // The value the same input reads back to display.
        expect(formatLocalDate(picked)).toBe('2026-09-09');

        // The value that is persisted and sent to the backend.
        expect(serializeFilters(filters)).toMatchObject({
            date_from: '2026-09-09',
            date_to: '2026-09-09',
        });
    });

    it('survives a save and a reload of a stored filter', () => {
        const stored = { date_from: '2026-09-09', date_to: '2026-09-09' };
        const reloaded = deserializeFilters(stored);

        expect(formatLocalDate(reloaded.dateFrom!)).toBe('2026-09-09');
        expect(serializeFilters({ ...NO_FILTERS, ...reloaded })).toMatchObject(
            stored,
        );
    });
});
