import type { CreditCardPayment } from '@/types/account';
import { describe, expect, it } from 'vitest';

import { statementPaymentText } from './statement-payment-badge';

const payment: CreditCardPayment = {
    transaction_id: 'payment-1',
    date: '2026-09-13',
    amount: 96335,
    period_from: '2026-08-07',
    closing_date: '2026-09-06',
    due_date: '2026-09-26',
};

describe('statementPaymentText', () => {
    it('writes the statement dates in the reader locale', () => {
        expect(statementPaymentText(payment, 'en-US')).toMatch(
            /^Statement payment Aug 7(, 2026| 2026)? – Sep 6(, 2026| 2026)?$/,
        );
        expect(statementPaymentText(payment, 'es-MX')).toMatch(
            /^Statement payment 7 ago\.?( 2026)? – 6 sept?\.?( 2026)?$/,
        );
    });
});
