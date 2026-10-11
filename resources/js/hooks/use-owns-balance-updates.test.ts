import { describe, expect, it } from 'vitest';
import { ownsBalanceUpdates } from './use-owns-balance-updates';

describe('ownsBalanceUpdates', () => {
    const manual = { type: 'checking' as const, banking_connection_id: null };

    it('lets a manual account move its balance', () => {
        expect(ownsBalanceUpdates(manual)).toBe(true);
    });

    it('never lets a connected account move its balance', () => {
        expect(
            ownsBalanceUpdates({
                ...manual,
                banking_connection_id: 'connection-1',
            }),
        ).toBe(false);
    });

    it('never lets a credit card move its balance', () => {
        expect(ownsBalanceUpdates({ ...manual, type: 'credit_card' })).toBe(
            false,
        );
    });
});
