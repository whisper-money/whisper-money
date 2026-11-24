import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { type Account } from '@/types/account';
import { type SetupChecklistStep } from '@/types/dashboard';
import { fireEvent, render, screen, within } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { SetupChecklistCard } from './setup-checklist-card';

const post = vi.fn();

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/routes/dashboard/setup', () => ({
    dismiss: { url: () => '/dashboard/setup/dismiss' },
}));

vi.mock('@/routes/budgets', () => ({
    index: () => ({ url: '/budgets' }),
}));

vi.mock('@/lib/categorize-href', () => ({
    categorizeHref: () => '/transactions?category_ids=uncategorized',
}));

vi.mock('@/components/accounts/update-balance-dialog', () => ({
    UpdateBalanceDialog: ({ account }: { account: Account }) => (
        <div role="dialog">{`Balance for ${account.name}`}</div>
    ),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { reload: vi.fn() },
    useHttp: () => ({ post }),
}));

const imported: SetupChecklistStep = {
    key: 'imported',
    done: true,
    count: 201,
    first_date: '2026-06-04',
    last_date: '2026-11-20',
    accounts_count: 1,
    account_name: 'BBVA nómina',
};

const spendingLimit: SetupChecklistStep = {
    key: 'spending_limit',
    done: true,
    limit: 168400,
    under_last_month: 25000,
    currency_code: 'EUR',
};

const categorize: SetupChecklistStep = {
    key: 'categorize',
    done: false,
    count: 196,
};

const balance: SetupChecklistStep = {
    key: 'balance',
    done: false,
    account: {
        id: 'loan-1',
        name: 'Hipoteca piso',
        type: 'loan',
        currency_code: 'EUR',
        bank: null,
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
    },
    others_count: 0,
};

function renderCard(steps: SetupChecklistStep[], canDismiss = true) {
    return render(
        <PrivacyModeProvider>
            <SetupChecklistCard checklist={{ steps }} canDismiss={canDismiss} />
        </PrivacyModeProvider>,
    );
}

/** The desktop grid's tile for a step, found by its title. */
function tile(title: string): HTMLElement {
    return screen
        .getAllByText(title)
        .map((node) => node.closest('li'))
        .find((node): node is HTMLLIElement => node !== null)!;
}

describe('SetupChecklistCard', () => {
    beforeEach(() => {
        post.mockReset();
    });

    it('counts the steps done and describes what was set up', () => {
        renderCard([imported, spendingLimit, categorize, balance]);

        expect(screen.getByText('2 of 4 done')).toBeTruthy();
        expect(
            within(tile('201 transactions imported')).getByText(
                'Jun – Nov, BBVA nómina',
            ),
        ).toBeTruthy();
        expect(
            within(tile('Monthly spending limit set')).getByText(
                '€1,684 — €250 under last month',
            ),
        ).toBeTruthy();
    });

    it('makes the first step not done the next one, with the primary action', () => {
        renderCard([imported, spendingLimit, categorize, balance]);

        const next = tile('Categorize 196 transactions');
        expect(within(next).getByText('Next')).toBeTruthy();
        expect(
            within(next)
                .getByRole('link', { name: 'Categorize' })
                .getAttribute('href'),
        ).toBe('/transactions?category_ids=uncategorized');

        const later = tile('Add what you still owe on Hipoteca piso');
        expect(within(later).getByText('To do')).toBeTruthy();
    });

    it('opens the balance dialog on the account that has none', () => {
        renderCard([imported, balance]);

        fireEvent.click(
            within(tile('Add what you still owe on Hipoteca piso')).getByRole(
                'button',
                { name: 'Add balance' },
            ),
        );

        expect(screen.getByRole('dialog').textContent).toBe(
            'Balance for Hipoteca piso',
        );
    });

    it('offers a spending limit to set when there is none', () => {
        renderCard([
            imported,
            {
                ...spendingLimit,
                done: false,
                limit: null,
                under_last_month: null,
            },
        ]);

        expect(
            within(tile('Set a monthly spending limit'))
                .getByRole('link', { name: 'Set limit' })
                .getAttribute('href'),
        ).toBe('/budgets');
    });

    it('names the step after the next one on the compact version', () => {
        renderCard([imported, spendingLimit, categorize, balance]);

        expect(
            screen.getByText('Then: Add what you still owe on Hipoteca piso'),
        ).toBeTruthy();
    });

    it('hides itself and records it when dismissed', () => {
        renderCard([imported, categorize]);

        fireEvent.click(screen.getByRole('button', { name: 'Hide' }));

        expect(post).toHaveBeenCalledWith('/dashboard/setup/dismiss');
        expect(screen.queryByText('Finish setting up')).toBeNull();
    });

    it('offers no way to hide it on a shared account', () => {
        renderCard([imported, categorize], false);

        expect(screen.queryByRole('button', { name: 'Hide' })).toBeNull();
    });
});
