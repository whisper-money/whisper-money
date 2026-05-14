import { setTranslations } from '@/utils/i18n';
import { afterEach, describe, expect, it } from 'vitest';
import { getBulkDeleteConfirmationText } from './transaction-delete-confirmation';

describe('getBulkDeleteConfirmationText', () => {
    afterEach(() => {
        setTranslations({});
    });

    it('returns translated bulk delete confirmation text', () => {
        setTranslations({
            'Delete :count Transactions': 'Borrar :count transacciones',
        });

        expect(getBulkDeleteConfirmationText(12)).toBe(
            'Borrar 12 transacciones',
        );
    });

    it('falls back to English confirmation text', () => {
        expect(getBulkDeleteConfirmationText(12)).toBe(
            'Delete 12 Transactions',
        );
    });
});
