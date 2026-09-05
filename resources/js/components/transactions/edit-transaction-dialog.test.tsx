import { transactionSyncService } from '@/services/transaction-sync';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { EditTransactionDialog } from './edit-transaction-dialog';

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
        getById: vi.fn(),
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: {
        visit: vi.fn(),
        reload: vi.fn(),
    },
    usePage: () => ({
        props: {
            auth: { user: { currency_code: 'EUR' } },
            currencies: {
                profile: [{ code: 'EUR', name: 'Euro' }],
                accounts: [
                    { code: 'EUR', name: 'Euro' },
                    { code: 'USD', name: 'US Dollar' },
                ],
                decimals: { EUR: 2, USD: 2 },
            },
        },
    }),
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
}));

vi.mock('@/components/ui/select', () => ({
    // The dialog renders more than one Select; a named one gets a testid of its
    // own so the account's stays unambiguous.
    Select: ({
        name,
        value,
        onValueChange,
        children,
    }: {
        name?: string;
        value?: string;
        onValueChange?: (value: string) => void;
        children: React.ReactNode;
    }) => (
        <div
            data-testid={name ? `${name}-value` : 'account-value'}
            data-value={value ?? ''}
            onClick={(event) => {
                const next = (event.target as HTMLElement).dataset.selectValue;
                if (next) {
                    onValueChange?.(next);
                }
            }}
        >
            {children}
        </div>
    ),
    SelectTrigger: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    SelectContent: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
    SelectItem: ({
        value,
        children,
    }: {
        value: string;
        children: React.ReactNode;
    }) => <div data-select-value={value}>{children}</div>,
    SelectValue: ({
        placeholder,
        children,
    }: {
        placeholder?: string;
        children?: React.ReactNode;
    }) => <span>{children ?? placeholder}</span>,
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

    it('shows counterparty names in the read-only details', () => {
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
        expect(screen.getByText('Amazon EU')).toBeInTheDocument();
        expect(screen.getByText('Debtor')).toBeInTheDocument();
        expect(screen.getByText('Victor Falcon')).toBeInTheDocument();
    });

    it('shows the whole concept in the header instead of truncating it', () => {
        const longDescription =
            'Adeudo de Comunidad de Propietarios y Verificacion Pago Mensual Extraordinario';

        render(
            <EditTransactionDialog
                transaction={{
                    id: 'tx-1',
                    user_id: 'user-1',
                    account_id: 'account-1',
                    category_id: null,
                    description: longDescription,
                    description_iv: null,
                    transaction_date: '2026-05-27',
                    amount: -1200,
                    currency_code: 'EUR',
                    notes: null,
                    notes_iv: null,
                    source: 'imported',
                    created_at: '2026-05-27T00:00:00Z',
                    updated_at: '2026-05-27T00:00:00Z',
                    decryptedDescription: longDescription,
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

        const header = screen.getByTestId('transaction-header-description');

        expect(header).toHaveTextContent(longDescription);
        expect(header.className).not.toContain('truncate');
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

    it('falls back to the profile currency while no account is selected', () => {
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

        expect(screen.getByText('EUR')).toBeInTheDocument();
        expect(screen.getByTestId('currency_code-value')).toHaveAttribute(
            'data-value',
            'EUR',
        );
    });

    it('shows each account currency in the account options', async () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[
                    checkingAccount,
                    {
                        ...checkingAccount,
                        id: 'account-2',
                        name: 'Savings',
                        currency_code: 'PEN',
                    },
                ]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
            />,
        );

        expect(await screen.findByText('Checking · EUR')).toBeInTheDocument();
        expect(screen.getByText('Savings · PEN')).toBeInTheDocument();
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

    it('checks "update account balance" by default in create mode', () => {
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

        expect(screen.getByRole('checkbox')).toBeChecked();
    });

    it('lets you edit every field of a manually created transaction', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    id: 'tx-manual',
                    user_id: 'user-1',
                    account_id: 'account-1',
                    category_id: null,
                    description: 'Groceries',
                    description_iv: null,
                    transaction_date: '2026-05-27',
                    amount: -1200,
                    currency_code: 'EUR',
                    notes: null,
                    notes_iv: null,
                    creditor_name: null,
                    debtor_name: null,
                    source: 'manually_created',
                    created_at: '2026-05-27T00:00:00Z',
                    updated_at: '2026-05-27T00:00:00Z',
                    decryptedDescription: 'Groceries',
                    decryptedNotes: null,
                    label_ids: [],
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        // Amount, date and description render as editable inputs, not read-only text.
        expect(screen.getByPlaceholderText('0.00')).toBeInTheDocument();
        expect(
            screen.getByPlaceholderText('Transaction description'),
        ).toBeInTheDocument();
        expect(
            document.querySelector('input[type="date"]'),
        ).toBeInTheDocument();
    });

    it('keeps amount and description read-only for an imported transaction but lets the date move', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    id: 'tx-imported',
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
                    creditor_name: null,
                    debtor_name: null,
                    source: 'imported',
                    created_at: '2026-05-27T00:00:00Z',
                    updated_at: '2026-05-27T00:00:00Z',
                    decryptedDescription: 'Card payment',
                    decryptedNotes: null,
                    label_ids: [],
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(screen.queryByPlaceholderText('0.00')).not.toBeInTheDocument();
        expect(
            screen.queryByPlaceholderText('Transaction description'),
        ).not.toBeInTheDocument();

        // The date still moves — it decides which month the row counts towards.
        expect(document.querySelector('input[type="date"]')).toHaveValue(
            '2026-05-27',
        );
        expect(
            screen.getByText(
                'The date decides which month and budget this transaction counts towards.',
            ),
        ).toBeInTheDocument();

        // The locked data shows up as a read-only details card instead.
        expect(screen.getByText('Card payment')).toBeInTheDocument();
        expect(screen.getByText('Imported from a file')).toBeInTheDocument();
        expect(
            screen.getByText('These details cannot be edited.'),
        ).toBeInTheDocument();
    });

    it('explains why the balance stays put on a connected account', () => {
        const connectedAccount = {
            ...checkingAccount,
            id: 'account-connected',
            banking_connection_id: 'connection-1',
        };

        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[connectedAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-connected"
            />,
        );

        expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
        expect(
            screen.getByText(
                "This account's balance comes from your bank, so it won't change.",
            ),
        ).toBeInTheDocument();
    });

    const manualTransaction = {
        id: 'tx-manual',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'Groceries',
        description_iv: null,
        transaction_date: '2026-05-27',
        amount: -1200,
        currency_code: 'EUR',
        notes: null,
        notes_iv: null,
        creditor_name: null,
        debtor_name: null,
        source: 'manually_created' as const,
        created_at: '2026-05-27T00:00:00Z',
        updated_at: '2026-05-27T00:00:00Z',
        decryptedDescription: 'Groceries',
        decryptedNotes: null,
        label_ids: [],
    };

    it('closes the dialog and asks the parent to delete when Delete is clicked', () => {
        const onDelete = vi.fn();
        const onOpenChange = vi.fn();

        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={onOpenChange}
                onSuccess={vi.fn()}
                onDelete={onDelete}
                mode="edit"
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Delete' }));

        expect(onOpenChange).toHaveBeenCalledWith(false);
        expect(onDelete).toHaveBeenCalledWith(manualTransaction);
    });

    it('hides the Delete button when no onDelete handler is provided', () => {
        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Delete' }),
        ).not.toBeInTheDocument();
    });

    it('keeps description editable for a split part while the rest stays locked', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    ...manualTransaction,
                    id: 'tx-part',
                    split_parent_id: 'tx-original',
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(
            screen.getByPlaceholderText('Transaction description'),
        ).toBeInTheDocument();
        expect(screen.queryByPlaceholderText('0.00')).not.toBeInTheDocument();
        expect(
            document.querySelector('input[type="date"]'),
        ).not.toBeInTheDocument();
        // The locked date shows up in the header and the details card instead.
        expect(screen.getAllByText('May 27').length).toBeGreaterThan(0);
        expect(
            screen.getByText('Part of a split transaction'),
        ).toBeInTheDocument();
    });

    const importedTransaction = {
        id: 'tx-imported',
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
        creditor_name: null,
        debtor_name: null,
        source: 'imported' as const,
        created_at: '2026-05-27T00:00:00Z',
        updated_at: '2026-05-27T00:00:00Z',
        decryptedDescription: 'Card payment',
        decryptedNotes: null,
        label_ids: [],
    };

    it('shows the source date once the transaction has been moved', () => {
        render(
            <EditTransactionDialog
                transaction={{
                    ...importedTransaction,
                    transaction_date: '2026-06-01',
                    source_date: '2026-05-27',
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(
            screen.getByTestId('original-transaction-date'),
        ).toHaveTextContent('Original date: May 27');
    });

    it('shows the source date as soon as the field moves off it, before saving', () => {
        render(
            <EditTransactionDialog
                transaction={importedTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        // Nothing to point at while the field still sits on the source's day.
        expect(
            screen.queryByTestId('original-transaction-date'),
        ).not.toBeInTheDocument();

        const dateInput = document.querySelector(
            'input[type="date"]',
        ) as HTMLInputElement;
        fireEvent.change(dateInput, { target: { value: '2026-06-01' } });

        expect(
            screen.getByTestId('original-transaction-date'),
        ).toHaveTextContent('Original date: May 27');

        // Moving it back onto the source's own day hides it again.
        fireEvent.change(dateInput, { target: { value: '2026-05-27' } });

        expect(
            screen.queryByTestId('original-transaction-date'),
        ).not.toBeInTheDocument();
    });

    it('never offers a source date on a manually created transaction', () => {
        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        const dateInput = document.querySelector(
            'input[type="date"]',
        ) as HTMLInputElement;
        fireEvent.change(dateInput, { target: { value: '2026-06-01' } });

        expect(
            screen.queryByTestId('original-transaction-date'),
        ).not.toBeInTheDocument();
    });

    it('persists description edits on a split part', async () => {
        vi.mocked(transactionSyncService.update).mockResolvedValue({} as never);

        render(
            <EditTransactionDialog
                transaction={{
                    ...manualTransaction,
                    id: 'tx-part',
                    split_parent_id: 'tx-original',
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        fireEvent.change(
            screen.getByPlaceholderText('Transaction description'),
            { target: { value: 'Groceries — my half' } },
        );
        fireEvent.click(screen.getByTestId('submit-transaction'));

        await waitFor(() => {
            expect(transactionSyncService.update).toHaveBeenCalledWith(
                'tx-part',
                expect.objectContaining({
                    description: 'Groceries — my half',
                }),
                expect.anything(),
            );
        });
    });

    it('submits expenses as negative amounts by default', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);

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

        fireEvent.change(
            screen.getByPlaceholderText('Transaction description'),
            { target: { value: 'Dinner' } },
        );
        const amountInput = screen.getByPlaceholderText('0.00');
        fireEvent.change(amountInput, { target: { value: '25' } });
        fireEvent.blur(amountInput);
        fireEvent.click(screen.getByTestId('submit-transaction'));

        await waitFor(() => {
            expect(transactionSyncService.create).toHaveBeenCalledWith(
                expect.objectContaining({ amount: -2500 }),
                expect.anything(),
            );
        });
    });

    it('submits positive amounts when income is selected', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue({
            id: 'tx-new',
        } as never);

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

        fireEvent.click(screen.getByTestId('transaction-type-income'));
        fireEvent.change(
            screen.getByPlaceholderText('Transaction description'),
            { target: { value: 'Salary' } },
        );
        const amountInput = screen.getByPlaceholderText('0.00');
        fireEvent.change(amountInput, { target: { value: '25' } });
        fireEvent.blur(amountInput);
        fireEvent.click(screen.getByTestId('submit-transaction'));

        await waitFor(() => {
            expect(transactionSyncService.create).toHaveBeenCalledWith(
                expect.objectContaining({ amount: 2500 }),
                expect.anything(),
            );
        });
    });

    it('shows the absolute amount with the expense toggle active when editing', () => {
        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(screen.getByDisplayValue('12.00')).toBeInTheDocument();
        expect(screen.getByTestId('transaction-type-expense')).toHaveAttribute(
            'data-state',
            'on',
        );
    });

    it('defaults the currency to the account it is being added to', () => {
        render(
            <EditTransactionDialog
                transaction={null}
                categories={[]}
                accounts={[{ ...checkingAccount, currency_code: 'USD' }]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="create"
                initialAccountId="account-1"
            />,
        );

        expect(screen.getByTestId('currency_code-value')).toHaveAttribute(
            'data-value',
            'USD',
        );
        expect(screen.getByText('USD')).toBeInTheDocument();
    });

    it('stores the picked currency rather than the account one', async () => {
        vi.mocked(transactionSyncService.create).mockResolvedValue(
            manualTransaction,
        );

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

        fireEvent.click(screen.getByText('USD - US Dollar'));

        fireEvent.change(
            screen.getByPlaceholderText('Transaction description'),
            { target: { value: 'Hotel' } },
        );
        const amountInput = screen.getByPlaceholderText('0.00');
        fireEvent.change(amountInput, { target: { value: '40' } });
        fireEvent.blur(amountInput);
        fireEvent.click(screen.getByTestId('submit-transaction'));

        await waitFor(() => {
            expect(transactionSyncService.create).toHaveBeenCalledWith(
                expect.objectContaining({
                    amount: -4000,
                    currency_code: 'USD',
                }),
                expect.anything(),
            );
        });
    });

    it('shows what a foreign-currency amount comes to in the account currency', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ amount: 3700 }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <EditTransactionDialog
                transaction={{ ...manualTransaction, currency_code: 'USD' }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(await screen.findByTestId('converted-amount')).toHaveTextContent(
            '\u2248 €37.00 in the account currency',
        );
        expect(fetchMock).toHaveBeenCalledWith(
            '/api/exchange-rate?from=USD&to=EUR&date=2026-05-27&amount=1200',
        );

        vi.unstubAllGlobals();
    });

    it('shows nothing extra when the transaction is in the account currency', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        render(
            <EditTransactionDialog
                transaction={manualTransaction}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        await waitFor(() => {
            expect(screen.getByDisplayValue('12.00')).toBeInTheDocument();
        });
        expect(
            screen.queryByTestId('converted-amount'),
        ).not.toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();

        vi.unstubAllGlobals();
    });

    it('keeps the original alone when no rate can be found', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ amount: null }),
            }),
        );

        render(
            <EditTransactionDialog
                transaction={{ ...manualTransaction, currency_code: 'USD' }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        await waitFor(() => {
            expect(screen.getByDisplayValue('12.00')).toBeInTheDocument();
        });
        expect(
            screen.queryByTestId('converted-amount'),
        ).not.toBeInTheDocument();

        vi.unstubAllGlobals();
    });

    it('adds the account-currency figure to a locked transaction details', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ amount: -1100 }),
            }),
        );

        render(
            <EditTransactionDialog
                transaction={{
                    ...manualTransaction,
                    source: 'imported' as const,
                    currency_code: 'USD',
                }}
                categories={[]}
                accounts={[checkingAccount]}
                banks={[]}
                labels={[]}
                open
                onOpenChange={vi.fn()}
                onSuccess={vi.fn()}
                mode="edit"
            />,
        );

        expect(
            await screen.findByText('In account currency'),
        ).toBeInTheDocument();
        expect(screen.getByText('-€11.00')).toBeInTheDocument();

        vi.unstubAllGlobals();
    });

    it('hides the Delete button in create mode', () => {
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
                onDelete={vi.fn()}
                mode="create"
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Delete' }),
        ).not.toBeInTheDocument();
    });
});
