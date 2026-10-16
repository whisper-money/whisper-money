import type { CreditCardDetail } from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { EditCreditCardDetailDialog } from './edit-credit-card-detail-dialog';

const { router } = vi.hoisted(() => ({
    router: { patch: vi.fn(), delete: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    router,
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

vi.mock('@/actions/App/Http/Controllers/CreditCardDetailController', () => ({
    update: { url: (id: string) => `/accounts/${id}/credit-card-detail` },
    destroy: { url: (id: string) => `/accounts/${id}/credit-card-detail` },
}));

const weeklyDetail: CreditCardDetail = {
    statement_closing_date: '2026-10-08',
    payment_due_date: '2026-10-12',
    statement_frequency: 'weekly',
    credit_limit: null,
};

function renderDialog(detail: CreditCardDetail | null) {
    render(
        <EditCreditCardDetailDialog
            accountId="card-1"
            currencyCode="EUR"
            detail={detail}
            open
            onOpenChange={() => {}}
        />,
    );
}

function frequencyOption(name: 'Monthly' | 'Weekly') {
    return screen.getByRole('radio', { name });
}

describe('EditCreditCardDetailDialog', () => {
    beforeEach(() => {
        router.patch.mockClear();
    });

    it('shows the stored frequency and its 7-day limit for a weekly card', () => {
        renderDialog(weeklyDetail);

        expect(frequencyOption('Weekly')).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(
            screen.getByText(/same day it closes or up to 7 days later/),
        ).toBeInTheDocument();
        expect(screen.getByText(/same weekday every week/)).toBeInTheDocument();
    });

    it('defaults a card without details to monthly, with a 45-day limit', () => {
        renderDialog(null);

        expect(frequencyOption('Monthly')).toHaveAttribute(
            'aria-checked',
            'true',
        );
        expect(
            screen.getByText(/same day it closes or up to 45 days later/),
        ).toBeInTheDocument();
    });

    it('updates the due-date hint when the frequency changes', () => {
        renderDialog(null);

        fireEvent.click(frequencyOption('Weekly'));

        expect(
            screen.getByText(/same day it closes or up to 7 days later/),
        ).toBeInTheDocument();
    });

    it('sends the chosen frequency with the dates', () => {
        renderDialog({ ...weeklyDetail, statement_frequency: 'monthly' });

        fireEvent.click(frequencyOption('Weekly'));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(router.patch).toHaveBeenCalledWith(
            '/accounts/card-1/credit-card-detail',
            {
                statement_closing_date: '2026-10-08',
                payment_due_date: '2026-10-12',
                statement_frequency: 'weekly',
                credit_limit: null,
            },
            expect.any(Object),
        );
    });

    it('shows a validation error on the frequency', () => {
        router.patch.mockImplementationOnce((_url, _data, options) =>
            options.onError({
                statement_frequency:
                    'The payment due date must be within 7 days of the statement closing date.',
            }),
        );
        renderDialog(weeklyDetail);

        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(screen.getByText(/must be within 7 days/)).toBeInTheDocument();
    });
});
