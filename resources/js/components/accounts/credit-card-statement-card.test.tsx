import type {
    CreditCardPreviousStatement,
    CreditCardStatement,
} from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CreditCardStatementCard } from './credit-card-statement-card';

const { page, router } = vi.hoisted(() => ({
    page: {
        props: {
            locale: 'en-US',
        },
    },
    router: { patch: vi.fn(), delete: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => page,
}));

vi.mock('@/actions/App/Http/Controllers/CreditCardDetailController', () => ({
    update: { url: (id: string) => `/accounts/${id}/credit-card-detail` },
    destroy: { url: (id: string) => `/accounts/${id}/credit-card-detail` },
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

const detail = {
    statement_closing_date: '2026-03-05',
    payment_due_date: '2026-03-20',
    statement_frequency: 'monthly' as const,
    credit_limit: 300000,
};

function statement(
    isFinal: boolean,
    amount = 12345,
    previous: Partial<CreditCardPreviousStatement> = {},
): CreditCardStatement {
    return {
        next_payment: {
            period_from: '2026-02-06',
            closing_date: '2026-03-05',
            due_date: '2026-03-20',
            amount,
            is_final: isFinal,
            paid: 0,
        },
        current_cycle: {
            period_from: '2026-03-06',
            closing_date: '2026-04-05',
            due_date: '2026-04-20',
            amount: 4000,
        },
        payments: [],
        previous_statement: {
            period_from: '2026-01-06',
            closing_date: '2026-02-05',
            due_date: '2026-02-20',
            purchases: 0,
            paid: 0,
            pending: 0,
            has_recorded_purchases: true,
            ...previous,
        },
    };
}

function renderStatement(cardStatement: CreditCardStatement) {
    return render(
        <CreditCardStatementCard
            accountId="card-1"
            currencyCode="EUR"
            detail={detail}
            statement={cardStatement}
        />,
    );
}

describe('CreditCardStatementCard', () => {
    beforeEach(() => {
        router.patch.mockClear();
        router.delete.mockClear();
    });

    it('invites the user to set the statement dates when there are none', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={null}
                statement={null}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Set statement dates/ }),
        );

        expect(
            screen.getByLabelText('Statement closing date'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Remove dates' }),
        ).not.toBeInTheDocument();
    });

    it('shows a closed statement as final, with the open cycle beside it', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={detail}
                statement={statement(true)}
            />,
        );

        expect(screen.getByText('€123.45')).toBeInTheDocument();
        expect(
            screen.getByText(/Expected on Mar 20, 2026/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Statement closed on Mar 5, 2026/),
        ).toBeInTheDocument();
        expect(screen.getByText('€40.00')).toBeInTheDocument();
        expect(
            screen.getByText(
                /closes on Apr 5, 2026 and is charged on Apr 20, 2026/,
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Estimate')).toBeInTheDocument();
        expect(screen.getByText('Monthly')).toBeInTheDocument();
    });

    it('labels the cycle of a weekly card', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={{ ...detail, statement_frequency: 'weekly' }}
                statement={statement(false)}
            />,
        );

        expect(screen.getByText('Weekly')).toBeInTheDocument();
    });

    it('warns that an open cycle can still grow', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={detail}
                statement={statement(false)}
            />,
        );

        expect(screen.getByText(/can still grow/)).toBeInTheDocument();
    });

    it('says there is nothing to pay when refunds cover the statement', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={detail}
                statement={statement(true, -500)}
            />,
        );

        expect(screen.getByText('Nothing to pay')).toBeInTheDocument();
        expect(screen.getByText('€5.00')).toBeInTheDocument();
    });

    it('saves and removes the statement dates', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={detail}
                statement={statement(true)}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Edit card details/ }),
        );
        fireEvent.change(screen.getByLabelText('Payment due date'), {
            target: { value: '2026-03-22' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/accounts/card-1/credit-card-detail',
            {
                statement_closing_date: '2026-03-05',
                payment_due_date: '2026-03-22',
                statement_frequency: 'monthly',
                credit_limit: 300000,
            },
            expect.any(Object),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Remove dates' }));

        expect(router.delete).toHaveBeenCalledWith(
            '/accounts/card-1/credit-card-detail',
            expect.any(Object),
        );
    });

    it('sets a credit limit without statement dates', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={null}
                statement={null}
            />,
        );

        fireEvent.click(
            screen.getByRole('button', { name: /Set statement dates/ }),
        );
        const limitInput = screen.getByLabelText('Credit limit');
        fireEvent.focus(limitInput);
        fireEvent.change(limitInput, { target: { value: '1500' } });
        fireEvent.blur(limitInput);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/accounts/card-1/credit-card-detail',
            {
                statement_closing_date: null,
                payment_due_date: null,
                statement_frequency: 'monthly',
                credit_limit: 150000,
            },
            expect.any(Object),
        );
    });

    it('keeps the empty state on a card with a limit but no statement dates', () => {
        render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={{
                    statement_closing_date: null,
                    payment_due_date: null,
                    statement_frequency: 'monthly',
                    credit_limit: 300000,
                }}
                statement={null}
            />,
        );

        expect(
            screen.getByRole('button', { name: /Edit card details/ }),
        ).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: /Set statement dates/ }),
        );

        expect(screen.getByLabelText('Statement closing date')).toHaveValue('');
        // The dialog focuses the limit, which shows it without grouping.
        expect(screen.getByLabelText('Credit limit')).toHaveValue('3000.00');
        expect(
            screen.queryByRole('button', { name: 'Remove dates' }),
        ).not.toBeInTheDocument();
    });

    it('sums up the previous statement against what was paid on it', () => {
        renderStatement(
            statement(true, 12345, {
                purchases: 50000,
                paid: 40000,
                pending: 10000,
            }),
        );

        const line = screen.getByTestId('previous-statement');
        expect(line).toHaveTextContent('Statement Jan 6, 2026 – Feb 5, 2026');
        expect(line).toHaveTextContent(
            /Purchases €500\.00\s*·\s*Paid €400\.00\s*·\s*Pending €100\.00/,
        );
    });

    it('says no purchases are recorded instead of reading a payment as an overpayment', () => {
        renderStatement(
            statement(true, 12345, {
                paid: 96335,
                has_recorded_purchases: false,
            }),
        );

        const line = screen.getByTestId('previous-statement');
        expect(line).toHaveTextContent(
            /Paid €963\.35\s*·\s*No purchases recorded for that period/,
        );
        expect(line).not.toHaveTextContent('Pending');
        expect(line).not.toHaveTextContent('Purchases €');
    });

    it('leaves the previous statement out while nothing was bought or paid on it', () => {
        renderStatement(statement(true));

        expect(
            screen.queryByTestId('previous-statement'),
        ).not.toBeInTheDocument();
    });

    it('notes that some purchases may be missing when more was paid than recorded', () => {
        renderStatement(
            statement(true, 12345, { purchases: 5000, paid: 96335 }),
        );

        expect(
            screen.getByText(
                'Some purchases from that period may not be recorded here.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByTestId('previous-statement')).toHaveTextContent(
            /Pending €0\.00/,
        );
    });

    it('says how much of the next payment is already paid', () => {
        const cardStatement = statement(true);
        cardStatement.next_payment.paid = 2000;
        renderStatement(cardStatement);

        expect(screen.getByText('Already paid')).toBeInTheDocument();
        expect(screen.getByText('€20.00')).toBeInTheDocument();
    });
});
