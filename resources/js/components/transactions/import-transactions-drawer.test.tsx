import type { Account } from '@/types/account';
import { DateFormat, type ColumnMapping } from '@/types/import';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

const {
    create,
    checkDuplicates,
    convertRowsToTransactions,
    collectBalancesToImport,
    mappingProps,
    parseFile,
    loadImportConfig,
    saveImportConfig,
    autoDetectColumns,
} = vi.hoisted(() => ({
    create: vi.fn(),
    checkDuplicates: vi.fn(),
    convertRowsToTransactions: vi.fn(),
    collectBalancesToImport: vi.fn(),
    mappingProps: vi.fn(),
    parseFile: vi.fn(),
    loadImportConfig: vi.fn(async () => null),
    saveImportConfig: vi.fn(),
    autoDetectColumns: vi.fn(() => ({})),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { create, checkDuplicates },
}));

// The file itself is beside the point here: the rows the mapping would produce
// are handed straight to the preview.
vi.mock('@/lib/file-parser', () => ({
    parseFile,
    autoDetectColumns,
    detectDateFormat: vi.fn(() => null),
    detectExpenseTypes: vi.fn(() => []),
    convertRowsToTransactions,
    collectBalancesToImport,
    isInAccountCurrency: vi.fn(() => true),
}));

vi.mock('@/lib/import-config-storage', () => ({
    loadImportConfig,
    saveImportConfig,
}));

// The two steps between the account and the preview only have to be walked
// through; what they show is covered by their own tests.
vi.mock('./import-step-upload', () => ({
    ImportStepUpload: ({
        onNext,
        onFileSelect,
    }: {
        onNext: () => void;
        onFileSelect: (file: File) => void;
    }) => (
        <>
            <button
                type="button"
                onClick={() => onFileSelect(new File(['x'], 'movements.csv'))}
            >
                pick file
            </button>
            <button type="button" onClick={onNext}>
                leave upload
            </button>
        </>
    ),
}));

// The mapping step records what it was handed, and can stand in for the user
// mapping a balance column.
vi.mock('./import-step-mapping', () => ({
    ImportStepMapping: (props: {
        onNext: () => void;
        onMappingChange: (field: 'balance', value: string) => void;
    }) => {
        mappingProps(props);

        return (
            <>
                <button
                    type="button"
                    onClick={() => props.onMappingChange('balance', 'Saldo')}
                >
                    map balance
                </button>
                <button type="button" onClick={props.onNext}>
                    leave mapping
                </button>
            </>
        );
    },
}));

vi.mock('sonner', () => ({
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
}));

vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'test-token' }));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
        setPrivacyMode: vi.fn(),
    }),
}));

// The preview fetches the account's latest transactions on mount; that band is
// not what these tests are about, so the request is left hanging.
vi.mock('axios', () => ({
    default: { get: () => new Promise(() => {}) },
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => ({
        props: {
            locale: 'en',
            currencies: { accounts: [{ code: 'EUR' }] },
        },
    }),
}));

const account: Account = {
    id: 'account-1',
    name: 'Checking',
    name_iv: null,
    encrypted: false,
    bank: null,
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

const creditCard: Account = {
    ...account,
    id: 'card-1',
    name: 'Visa',
    type: 'credit_card',
};

const rows = [
    {
        transaction_date: '2026-03-01',
        description: 'Coffee',
        amount: -450,
        balance: 10000,
    },
    {
        transaction_date: '2026-03-02',
        description: 'Rent',
        amount: -90000,
        balance: 9550,
    },
    {
        transaction_date: '2026-03-03',
        description: 'Salary',
        amount: 250000,
        balance: 259550,
    },
];

/** The descriptions the sync service was asked to create, in order. */
function createdDescriptions(): string[] {
    return create.mock.calls.map(
        (call) => (call[0] as { description: string }).description,
    );
}

/** The props the mapping step was last rendered with. */
function lastMappingProps(): {
    tracksBalance: boolean;
    columnMapping: Partial<ColumnMapping>;
} {
    return mappingProps.mock.calls.at(-1)?.[0];
}

async function reachMapping(target: Account = account) {
    render(
        <ImportTransactionsDrawer
            open
            onOpenChange={vi.fn()}
            accounts={[target]}
            autoSelectSingleAccount
        />,
    );

    fireEvent.click(await screen.findByText('leave upload'));
    await screen.findByText('leave mapping');
}

async function reachPreview(target: Account = account) {
    await reachMapping(target);
    fireEvent.click(screen.getByText('leave mapping'));

    await screen.findByText('Import 3 Transactions');
}

describe('ImportTransactionsDrawer', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        collectBalancesToImport.mockReturnValue(new Map());
        checkDuplicates.mockResolvedValue([false, false, false]);
        convertRowsToTransactions.mockReturnValue(
            rows.map((row) => ({ ...row })),
        );
    });

    // The drawer is portalled out of the page, so only a class on its own
    // content keeps PostHog autocapture off the names and amounts inside.
    it('keeps PostHog autocapture off the whole drawer', async () => {
        await reachMapping();

        expect(screen.getByRole('dialog')).toHaveClass('ph-no-capture');
    });

    // The drawer stays open on a partial import so the failures can be retried.
    // Retrying used to resend every row, creating the ones that were already in
    // a second time.
    it('resends only the failed rows after a partial import', async () => {
        create.mockImplementation(
            async (transaction: { description: string }) =>
                transaction.description === 'Rent'
                    ? Promise.reject(new Error('Server said no'))
                    : { id: transaction.description },
        );

        await reachPreview();

        fireEvent.click(screen.getByText('Import 3 Transactions'));

        await waitFor(() => {
            expect(createdDescriptions()).toEqual(['Coffee', 'Rent', 'Salary']);
        });

        // Only the row that failed is still selected, and it is the only one
        // the button offers to import again.
        const retry = await screen.findByText('Import 1 Transaction');
        create.mockClear();
        fireEvent.click(retry);

        await waitFor(() => {
            expect(createdDescriptions()).toEqual(['Rent']);
        });
    });

    // Console output ends up in Sentry breadcrumbs: a failed row is logged by
    // its number and message only, never with its description or amount, nor
    // with the raw error, whose request config carries the row it sent.
    it('logs a failed row without the transaction or the request it sent', async () => {
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        create.mockImplementation(
            async (transaction: { description: string; amount: number }) =>
                transaction.description === 'Rent'
                    ? Promise.reject(
                          Object.assign(new Error('Server said no'), {
                              config: { data: JSON.stringify(transaction) },
                          }),
                      )
                    : { id: transaction.description },
        );

        await reachPreview();
        fireEvent.click(screen.getByText('Import 3 Transactions'));

        await screen.findByText('Import 1 Transaction');

        expect(consoleError).toHaveBeenCalledWith(
            'Transaction 2 failed:',
            'Server said no',
        );
        const logged = JSON.stringify(consoleError.mock.calls);
        expect(logged).not.toContain('Rent');
        expect(logged).not.toContain('90000');

        consoleError.mockRestore();
    });

    it('marks the rows that made it in and locks them out of the selection', async () => {
        create.mockImplementation(
            async (transaction: { description: string }) =>
                transaction.description === 'Rent'
                    ? Promise.reject(new Error('Server said no'))
                    : { id: transaction.description },
        );

        await reachPreview();

        fireEvent.click(screen.getByText('Import 3 Transactions'));

        expect(await screen.findAllByText('Imported')).toHaveLength(2);
        expect(
            screen
                .getByLabelText('Select transaction: Coffee')
                .getAttribute('disabled'),
        ).not.toBeNull();

        // Select-all cannot pick an imported row back up either. Here the only
        // selectable row is already picked, so the header reads "all selected":
        // clear it, then select all again.
        fireEvent.click(screen.getByLabelText('Select all transactions'));
        fireEvent.click(screen.getByLabelText('Select all transactions'));

        expect(await screen.findByText('Import 1 Transaction')).not.toBeNull();
    });

    it("applies the account's stored layout, type column and expense values included", async () => {
        const stored: ColumnMapping = {
            transaction_date: 'Fecha',
            description: 'Concepto',
            amount: 'Importe',
            currency: null,
            balance: null,
            creditor_name: '',
            debtor_name: null,
            transaction_type: 'Tipo',
            expense_types: ['Gasto', 'Cargo'],
        };
        parseFile.mockResolvedValue({
            headers: ['Fecha', 'Concepto', 'Importe', 'Tipo'],
            data: [],
            rowNumbers: [],
            columns: [],
            headerRowIndex: 0,
        });
        loadImportConfig.mockResolvedValue({
            columnMapping: stored,
            dateFormat: DateFormat.DayMonthYear,
        } as never);

        render(
            <ImportTransactionsDrawer
                open
                onOpenChange={vi.fn()}
                accounts={[account]}
                autoSelectSingleAccount
            />,
        );

        fireEvent.click(await screen.findByText('pick file'));
        await waitFor(() => expect(loadImportConfig).toHaveBeenCalled());
        fireEvent.click(screen.getByText('leave upload'));
        await screen.findByText('leave mapping');

        await waitFor(() =>
            expect(lastMappingProps().columnMapping).toEqual(stored),
        );
    });

    // The parser spots a currency column for the full import; this drawer
    // imports in the account's currency, and saving that column with the
    // account's layout made the next file without it skip the saved layout.
    it('neither maps nor saves a currency column', async () => {
        autoDetectColumns.mockReturnValueOnce({
            transaction_date: 'Fecha',
            description: 'Concepto',
            amount: 'Importe',
            currency: 'Divisa',
            balance: null,
            creditor_name: null,
            debtor_name: null,
            transaction_type: null,
            expense_types: [],
        });
        parseFile.mockResolvedValue({
            headers: ['Fecha', 'Concepto', 'Importe', 'Divisa'],
            data: [],
            rowNumbers: [],
            columns: [],
            headerRowIndex: 0,
        });

        render(
            <ImportTransactionsDrawer
                open
                onOpenChange={vi.fn()}
                accounts={[account]}
                autoSelectSingleAccount
            />,
        );

        fireEvent.click(await screen.findByText('pick file'));
        await waitFor(() => expect(loadImportConfig).toHaveBeenCalled());
        fireEvent.click(screen.getByText('leave upload'));
        await screen.findByText('leave mapping');

        expect(lastMappingProps().columnMapping).toMatchObject({
            amount: 'Importe',
            currency: null,
        });

        fireEvent.click(screen.getByText('leave mapping'));
        await screen.findByText('Import 3 Transactions');

        // The layout is remembered only once the import brought rows in.
        expect(saveImportConfig).not.toHaveBeenCalled();

        create.mockImplementation(
            async (transaction: { description: string }) => ({
                id: transaction.description,
            }),
        );
        fireEvent.click(screen.getByText('Import 3 Transactions'));

        await waitFor(() => expect(saveImportConfig).toHaveBeenCalled());
        expect(saveImportConfig).toHaveBeenCalledWith(
            'account-1',
            expect.objectContaining({
                columnMapping: expect.objectContaining({
                    amount: 'Importe',
                    currency: null,
                }),
            }),
        );
    });

    describe('balances', () => {
        it('offers a checking account the balance column', async () => {
            await reachMapping(account);
            fireEvent.click(screen.getByText('map balance'));

            expect(lastMappingProps().tracksBalance).toBe(true);
            expect(lastMappingProps().columnMapping.balance).toBe('Saldo');
        });

        it('hides the balance controls of a credit card, even a stale balance mapping', async () => {
            await reachMapping(creditCard);
            fireEvent.click(screen.getByText('map balance'));

            expect(lastMappingProps().tracksBalance).toBe(false);
            expect(lastMappingProps().columnMapping.balance).toBeNull();
        });

        it('keeps the balances of a checking account in the preview', async () => {
            await reachPreview(account);

            expect(screen.queryAllByText('Balance')).not.toHaveLength(0);
        });

        it('strips the balance of every row of a credit card and writes none', async () => {
            create.mockImplementation(
                async (transaction: { description: string }) => ({
                    id: transaction.description,
                }),
            );
            collectBalancesToImport.mockReturnValue(
                new Map([['2026-03-03', 259550]]),
            );

            await reachPreview(creditCard);

            expect(screen.queryAllByText('Balance')).toHaveLength(0);

            fireEvent.click(screen.getByText('Import 3 Transactions'));

            await waitFor(() => expect(create).toHaveBeenCalledTimes(3));
            expect(
                create.mock.calls.map(
                    (call) => (call[0] as { balance?: number }).balance,
                ),
            ).not.toContain(259550);
        });
    });
});
