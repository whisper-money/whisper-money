import type { CreditCardStatement } from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { CreditCardStatementCard } from './credit-card-statement-card';

const { page, router } = vi.hoisted(() => ({
    page: {
        props: {
            locale: 'en-US',
            features: { creditCardStatements: true },
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
};

function statement(isFinal: boolean, amount = 12345): CreditCardStatement {
    return {
        next_payment: {
            period_from: '2026-02-06',
            closing_date: '2026-03-05',
            due_date: '2026-03-20',
            amount,
            is_final: isFinal,
        },
        current_cycle: {
            period_from: '2026-03-06',
            closing_date: '2026-04-05',
            due_date: '2026-04-20',
            amount: 4000,
        },
    };
}

describe('CreditCardStatementCard', () => {
    beforeEach(() => {
        page.props.features.creditCardStatements = true;
        router.patch.mockClear();
        router.delete.mockClear();
    });

    it('renders nothing while the feature is off', () => {
        page.props.features.creditCardStatements = false;

        const { container } = render(
            <CreditCardStatementCard
                accountId="card-1"
                currencyCode="EUR"
                detail={detail}
                statement={statement(true)}
            />,
        );

        expect(container).toBeEmptyDOMElement();
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
            screen.queryByRole('button', { name: 'Remove' }),
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
        expect(screen.getByText(/Charged on Mar 20, 2026/)).toBeInTheDocument();
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

        fireEvent.click(screen.getByRole('button', { name: /Edit dates/ }));
        fireEvent.change(screen.getByLabelText('Payment due date'), {
            target: { value: '2026-03-22' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/accounts/card-1/credit-card-detail',
            {
                statement_closing_date: '2026-03-05',
                payment_due_date: '2026-03-22',
            },
            expect.any(Object),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Remove' }));

        expect(router.delete).toHaveBeenCalledWith(
            '/accounts/card-1/credit-card-detail',
            expect.any(Object),
        );
    });
});
