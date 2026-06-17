import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EditTransactionDialog } from './edit-transaction-dialog';

vi.mock('@/actions/App/Http/Controllers/AccountBalanceController', () => ({
    indexBalances: () => ({ url: '/balances' }),
    store: () => ({ url: '/balances' }),
    index: () => ({ url: '/balances' }),
}));

vi.mock('@/components/shared/label-combobox', () => ({
    LabelCombobox: () => <div />,
}));

vi.mock('@/components/transactions/category-select', () => ({
    CategorySelect: () => <div />,
}));

vi.mock('@/contexts/sync-context', () => ({
    useSyncContext: () => ({ sync: vi.fn() }),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

vi.mock('@/lib/key-storage', () => ({
    getStoredKey: () => null,
}));

vi.mock('@/lib/crypto', () => ({
    decrypt: vi.fn(),
    importKey: vi.fn(),
}));

vi.mock('@/lib/rule-engine', () => ({
    evaluateRulesForNewTransaction: vi.fn(),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: {
        create: vi.fn(),
        update: vi.fn(),
    },
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

vi.mock('@/components/ui/select', () => ({
    Select: ({
        value,
        children,
    }: {
        value?: string;
        children: React.ReactNode;
    }) => (
        <div data-testid="account-value" data-value={value ?? ''}>
            {children}
        </div>
    ),
    SelectTrigger: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    SelectContent: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    SelectItem: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    SelectValue: ({ placeholder }: { placeholder?: string }) => (
        <span>{placeholder}</span>
    ),
}));

vi.mock('@/components/ui/dialog', () => ({
    Dialog: ({
        children,
        open,
    }: {
        children: React.ReactNode;
        open: boolean;
    }) => (open ? <div>{children}</div> : null),
    DialogContent: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DialogDescription: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DialogFooter: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DialogHeader: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    DialogTitle: ({ children }: { children: React.ReactNode }) => (
        <h2>{children}</h2>
    ),
}));

describe('EditTransactionDialog', () => {
    beforeEach(() => {
        globalThis.ResizeObserver = class {
            observe() {}
            unobserve() {}
            disconnect() {}
        };
        Object.defineProperty(globalThis, 'localStorage', {
            value: {
                getItem: vi.fn(() => null),
                setItem: vi.fn(),
            },
            configurable: true,
        });
    });

    it('shows counterparty names as read-only fields', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    id: 'tx-1',
                    user_id: 'user-1',
                    account_id: 'account-1',
                    category_id: null,
                    description: 'Card payment',
                    description_iv: null,
                    transaction_date: '2026-05-27',
                    amount: -1200,
                    currency_code: 'EUR',
                    notes: null,
                    notes_iv: null,
                    creditor_name: 'Amazon EU',
                    debtor_name: 'Victor Falcon',
                    source: 'imported',
                    created_at: '2026-05-27T00:00:00Z',
                    updated_at: '2026-05-27T00:00:00Z',
                    decryptedDescription: 'Card payment',
                    decryptedNotes: null,
                    label_ids: [],
                }}
                categories={[]}
                accounts={[]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(screen.getByText('Creditor')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Amazon EU')).toBeDisabled();
        expect(screen.getByText('Debtor')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Victor Falcon')).toBeDisabled();
    });

    const checkingAccount = {
        id: 'account-1',
        name: 'Checking',
        name_iv: null,
        encrypted: false,
        bank: null,
        type: 'checking' as const,
        currency_code: 'EUR',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
    };

    it('does not auto-select an account when no initialAccountId is given', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
            />,
        );

        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            '',
        );
    });

    it('auto-selects the account matching initialAccountId (account page)', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        expect(screen.getByTestId('account-value')).toHaveAttribute(
            'data-value',
            'account-1',
        );
    });
});
