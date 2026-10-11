import { describe, expect, it } from 'vitest';
import { ownsBalanceUpdates } from './use-owns-balance-updates';

describe('ownsBalanceUpdates', () => {
    const manual = { type: 'checking' as const, banking_connection_id: null };
    const card = { type: 'credit_card' as const, banking_connection_id: null };

    it('lets a manual account move its balance whatever the feature', () => {
        expect(ownsBalanceUpdates(manual, false)).toBe(true);
        expect(ownsBalanceUpdates(manual, true)).toBe(true);
    });

    it('never lets a connected account move its balance', () => {
        expect(
            ownsBalanceUpdates(
                { ...manual, banking_connection_id: 'connection-1' },
                false,
            ),
        ).toBe(false);
    });

    it('lets a credit card move its balance only while the feature is off', () => {
        expect(ownsBalanceUpdates(card, false)).toBe(true);
        expect(ownsBalanceUpdates(card, true)).toBe(false);
    });
});
