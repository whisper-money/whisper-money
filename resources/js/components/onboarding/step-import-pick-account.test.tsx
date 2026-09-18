import { type Account } from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    importTargets,
    StepImportPickAccount,
} from './step-import-pick-account';

function account(overrides: Partial<Account>): Account {
    return {
        id: 'account-1',
        name: 'Everyday account',
        bank: null,
        type: 'checking',
        currency_code: 'EUR',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
        ...overrides,
    } as Account;
}

describe('importTargets', () => {
    it('takes a file for an account nobody else is filling', () => {
        const [target] = importTargets([account({})]);

        expect(target.eligible).toBe(true);
    });

    // Importing a file over a connection is how a year of history lands twice.
    it('refuses an account the bank is already syncing', () => {
        const [target] = importTargets([
            account({ banking_connection_id: 'connection-1' }),
        ]);

        expect(target.eligible).toBe(false);
    });

    it('refuses an account that holds a balance rather than movements', () => {
        const [target] = importTargets([account({ type: 'retirement' })]);

        expect(target.eligible).toBe(false);
    });

    it('leaves an archived account off the list altogether', () => {
        expect(importTargets([account({ archived_at: '2026-01-01' })])).toEqual(
            [],
        );
    });
});

describe('StepImportPickAccount', () => {
    const targets = importTargets([
        account({}),
        account({
            id: 'account-2',
            name: 'Cuenta Nómina',
            banking_connection_id: 'connection-1',
        }),
    ]);

    it('says why the connected account is not on offer', () => {
        render(
            <StepImportPickAccount
                targets={targets}
                selectedAccountId="account-1"
                onSelect={vi.fn()}
                onContinue={vi.fn()}
            />,
        );

        expect(screen.getByText('Not eligible')).toBeTruthy();
        expect(
            screen.getByText(/you end up with everything twice/),
        ).toBeTruthy();
    });

    it('does not let the connected account be chosen', () => {
        const onSelect = vi.fn();

        render(
            <StepImportPickAccount
                targets={targets}
                selectedAccountId="account-1"
                onSelect={onSelect}
                onContinue={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByText('Cuenta Nómina'));

        expect(onSelect).not.toHaveBeenCalled();
    });

    it('cannot continue without an account', () => {
        const onContinue = vi.fn();

        render(
            <StepImportPickAccount
                targets={targets}
                selectedAccountId={null}
                onSelect={vi.fn()}
                onContinue={onContinue}
            />,
        );

        fireEvent.click(screen.getByText('Continue'));

        expect(onContinue).not.toHaveBeenCalled();
    });
});
