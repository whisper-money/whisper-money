import type { Account } from '@/types/account';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

const { create, checkDuplicates, convertRowsToTransactions } = vi.hoisted(
    () => ({
        create: vi.fn(),
        checkDuplicates: vi.fn(),
        convertRowsToTransactions: vi.fn(),
    }),
);

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
    collectBalancesToImport: vi.fn(() => new Map()),
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

vi.mock('./import-step-mapping', () => ({
    ImportStepMapping: ({ onNext }: { onNext: () => void }) => (
        <button type="button" onClick={onNext}>
            leave mapping
        </button>
    ),
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
    name_iv: null,
    encrypted: false,
    bank: null,
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

const rows = [
    { transaction_date: '2026-03-01', description: 'Coffee', amount: -450 },
    { transaction_date: '2026-03-02', description: 'Rent', amount: -90000 },
    { transaction_date: '2026-03-03', description: 'Salary', amount: 250000 },
];

/** The descriptions the sync service was asked to create, in order. */
function createdDescriptions(): string[] {
    return create.mock.calls.map(
        (call) => (call[0] as { description: string }).description,
    );
}

async function reachPreview() {
    render(
        <ImportTransactionsDrawer
            open
            onOpenChange={vi.fn()}
            accounts={[account]}
        />,
    );

    fireEvent.click(await screen.findByRole('radio'));
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    fireEvent.click(await screen.findByText('leave upload'));
    fireEvent.click(await screen.findByText('leave mapping'));

    await screen.findByText('Import 3 transactions');
}

describe('ImportTransactionsDrawer', () => {
    beforeEach(() => {
        vi.clearAllMocks();
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
});
