import type { Account } from '@/types/account';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

const {
    create,
    checkDuplicates,
    convertRowsToTransactions,
    collectBalancesToImport,
    mappingProps,
} = vi.hoisted(() => ({
    create: vi.fn(),
    checkDuplicates: vi.fn(),
    convertRowsToTransactions: vi.fn(),
    collectBalancesToImport: vi.fn(),
    mappingProps: vi.fn(),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { create, checkDuplicates },
}));

// The file itself is beside the point here: the rows the mapping would produce
// are handed straight to the preview.
vi.mock('@/lib/file-parser', () => ({
    parseFile: vi.fn(),
    autoDetectColumns: vi.fn(() => ({})),
    detectDateFormat: vi.fn(() => null),
    convertRowsToTransactions,
    collectBalancesToImport,
    calculateBalancesFromTransactions: vi.fn(() => new Map()),
    isInAccountCurrency: vi.fn(() => true),
}));

vi.mock('@/lib/import-config-storage', () => ({
    loadImportConfig: vi.fn(async () => null),
    saveImportConfig: vi.fn(),
}));

// The two steps between the account and the preview only have to be walked
// through; what they show is covered by their own tests.
vi.mock('@/components/import-step-upload', () => ({
    ImportStepUpload: ({ onNext }: { onNext: () => void }) => (
        <button type="button" onClick={onNext}>
            leave upload
        </button>
    ),
}));

// The mapping step records what it was handed, and can stand in for the user
// mapping a balance column or asking for balances to be worked out.
vi.mock('./import-step-mapping', () => ({
    ImportStepMapping: (props: {
        onNext: () => void;
        onMappingChange: (field: 'balance', value: string) => void;
        onCalculateBalancesChange: (enabled: boolean) => void;
        onLatestDateChange: (date: string | null) => void;
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
                <button
                    type="button"
                    onClick={() => {
                        props.onCalculateBalancesChange(true);
                        props.onLatestDateChange('2026-03-03');
                    }}
                >
                    calculate balances
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
            features: { calculateBalancesOnImport: false },
            currencies: { accounts: [{ code: 'EUR' }] },
        },
    }),
}));

const account: Account = {
    id: 'account-1',
    name: 'Checking',
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
    calculateBalances: boolean;
    columnMapping: { balance: string | null };
} {
    return mappingProps.mock.calls.at(-1)?.[0];
}

async function reachMapping(target: Account = account) {
    render(
        <ImportTransactionsDrawer
            open
            onOpenChange={vi.fn()}
            accounts={[target]}
        />,
    );

    fireEvent.click(await screen.findByRole('radio'));
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    fireEvent.click(await screen.findByText('leave upload'));
    await screen.findByText('leave mapping');
}

async function reachPreview(target: Account = account) {
    await reachMapping(target);
    fireEvent.click(screen.getByText('leave mapping'));

    await screen.findByText('Import 3 transactions');
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

        fireEvent.click(screen.getByText('Import 3 transactions'));

        await waitFor(() => {
            expect(createdDescriptions()).toEqual(['Coffee', 'Rent', 'Salary']);
        });

        // Only the row that failed is still selected, and it is the only one
        // the button offers to import again.
        const retry = await screen.findByText('Import 1 transaction');
        create.mockClear();
        fireEvent.click(retry);

        await waitFor(() => {
            expect(createdDescriptions()).toEqual(['Rent']);
        });
    });

    it('marks the rows that made it in and locks them out of the selection', async () => {
        create.mockImplementation(
            async (transaction: { description: string }) =>
                transaction.description === 'Rent'
                    ? Promise.reject(new Error('Server said no'))
                    : { id: transaction.description },
        );

        await reachPreview();

        fireEvent.click(screen.getByText('Import 3 transactions'));

        expect(await screen.findAllByText('Imported')).toHaveLength(2);
        expect(
            screen.getByLabelText('Select Coffee').getAttribute('disabled'),
        ).not.toBeNull();

        // Select-all cannot pick an imported row back up either.
        fireEvent.click(screen.getByLabelText('Select all transactions'));

        expect(await screen.findByText('Import 1 transaction')).not.toBeNull();
    });

    describe('balances', () => {
        const fetchMock = vi.fn();

        beforeEach(() => {
            fetchMock.mockReset();
            fetchMock.mockResolvedValue({
                ok: true,
                json: async () => ({ data: [] }),
            });
            vi.stubGlobal('fetch', fetchMock);
        });

        it('offers a checking account the balance column and the calculate toggle', async () => {
            await reachMapping(account);
            fireEvent.click(screen.getByText('map balance'));

            expect(lastMappingProps().tracksBalance).toBe(true);
            expect(lastMappingProps().columnMapping.balance).toBe('Saldo');
        });

        it('hides the balance controls of a credit card, even a stale balance mapping', async () => {
            await reachMapping(creditCard);
            fireEvent.click(screen.getByText('map balance'));
            fireEvent.click(screen.getByText('calculate balances'));

            expect(lastMappingProps().tracksBalance).toBe(false);
            expect(lastMappingProps().columnMapping.balance).toBeNull();
            expect(lastMappingProps().calculateBalances).toBe(false);
        });

        it('looks the reference balance up for a checking account only', async () => {
            await reachMapping(account);
            fireEvent.click(screen.getByText('calculate balances'));

            await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        });

        it('never looks a reference balance up for a credit card', async () => {
            await reachMapping(creditCard);
            fireEvent.click(screen.getByText('calculate balances'));
            fireEvent.click(screen.getByText('leave mapping'));
            await screen.findByText('Import 3 transactions');

            expect(fetchMock).not.toHaveBeenCalled();
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

            fireEvent.click(screen.getByText('Import 3 transactions'));

            await waitFor(() => expect(create).toHaveBeenCalledTimes(3));
            expect(
                create.mock.calls.map(
                    (call) => (call[0] as { balance?: number }).balance,
                ),
            ).not.toContain(259550);
            expect(fetchMock).not.toHaveBeenCalled();
        });
    });
});
