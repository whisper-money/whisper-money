import { seedPageState } from '@/lib/page-state';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { describe, expect, it } from 'vitest';

describe('seedPageState', () => {
    it('seeds the translations a render reads before its first paint', () => {
        seedPageState({ translations: { Summary: 'Resumen' } });

        expect(__('Summary')).toBe('Resumen');
    });

    it('seeds the currency scale from the server table', () => {
        seedPageState({
            currencies: { profile: [], accounts: [], decimals: { BTC: 8 } },
        });

        expect(formatCurrency(1, 'BTC', 'en-US')).toContain('0.00000001');
    });

    it('clears both when the page carries neither', () => {
        seedPageState({ translations: { Summary: 'Resumen' } });
        seedPageState(undefined);

        expect(__('Summary')).toBe('Summary');
    });
});
