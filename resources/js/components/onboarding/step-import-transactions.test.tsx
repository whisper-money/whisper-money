import { type Account } from '@/types/account';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepImportTransactions } from './step-import-transactions';

const {
    captureEvent,
    checkDuplicates,
    convertRowsToTransactions,
    buildMappingReport,
    importTransactions,
    parseImportFile,
    accounts,
} = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    checkDuplicates: vi.fn(),
    convertRowsToTransactions: vi.fn(),
    buildMappingReport: vi.fn(),
    importTransactions: vi.fn(),
    parseImportFile: vi.fn(),
    accounts: { current: [] as Partial<Account>[] },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('@inertiajs/react', () => ({
    router: { reload: vi.fn() },
    usePage: () => ({
        props: {
            accounts: accounts.current,
            categories: [],
            banks: [],
            automationRules: [],
            locale: 'en-US',
            currencies: { accounts: [{ code: 'EUR' }] },
        },
    }),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { checkDuplicates },
}));

vi.mock('@/lib/import-config-storage', () => ({
    saveImportConfig: vi.fn(),
    loadImportConfig: vi.fn(async () => null),
}));

vi.mock('@/lib/file-parser', () => ({
    convertRowsToTransactions,
    buildMappingReport,
}));

// The reading and the writing are the shared library's job and are covered
// there; what this step owns is the order the screens come in.
vi.mock('@/lib/transaction-import', async (original) => ({
    ...(await original<typeof import('@/lib/transaction-import')>()),
    parseImportFile,
    importTransactions,
    applyStoredImportConfig: vi.fn(async () => null),
}));

const CHECKING: Partial<Account> = {
    id: 'account-1',
    name: 'Everyday account',
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: null,
    bank: null,
};

const CONNECTED: Partial<Account> = {
    id: 'account-2',
    name: 'Cuenta Nómina',
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: 'connection-1',
    bank: null,
};

const SAVINGS: Partial<Account> = {
    id: 'account-3',
    name: 'Rainy day',
    type: 'savings',
    currency_code: 'EUR',
    banking_connection_id: null,
    bank: null,
};

const ROWS = [
    { transaction_date: '2026-08-14', description: 'Glovo', amount: -2480 },
    { transaction_date: '2026-08-13', description: 'Mercadona', amount: -6240 },
];

function renderStep(onComplete = vi.fn()) {
    const view = render(
        <StepImportTransactions account={undefined} onComplete={onComplete} />,
    );

    return { ...view, onComplete };
}

async function chooseFile(container: HTMLElement, name = 'movements.csv') {
    const input = container.querySelector(
        'input[type="file"]',
    ) as HTMLInputElement;

    fireEvent.change(input, {
        target: { files: [new File(['date,amount'], name)] },
    });

    await screen.findByText('Did we read it right?');
}

/** Walk from the column step to the preview. */
async function confirmColumns() {
    fireEvent.click(screen.getByText("That's right"));

    await screen.findByText('Use a different file');
}

beforeEach(() => {
    vi.clearAllMocks();
    accounts.current = [CHECKING];
    parseImportFile.mockResolvedValue({
        file: new File([''], 'movements.csv'),
        rows: [{}, {}],
        rowNumbers: [2, 3],
        headers: ['Date', 'Amount'],
        columnOptions: [],
        mapping: {
            transaction_date: 'Date',
            description: 'Concept',
            amount: 'Amount',
            currency: null,
            balance: null,
            creditor_name: null,
            debtor_name: null,
        },
        dateFormat: 'YYYY-MM-DD',
        dateFormatDetected: true,
        dateFormatAmbiguous: false,
    });
    convertRowsToTransactions.mockReturnValue(ROWS);
    buildMappingReport.mockReturnValue({ problems: [] });
    checkDuplicates.mockResolvedValue([false, false]);
    importTransactions.mockResolvedValue({
        imported: [true, true],
        errors: [],
        successCount: 2,
        uncategorizedCount: 0,
    });
});

describe('StepImportTransactions account choice', () => {
    it('does not ask which account when only one can take a file', async () => {
        renderStep();

        expect(await screen.findByText('Bring in your history')).toBeTruthy();
    });

    it('asks which account when more than one can take a file', async () => {
        accounts.current = [CHECKING, SAVINGS];

        renderStep();

        expect(
            await screen.findByText('Which account is this file from?'),
        ).toBeTruthy();
    });

    // A connected account is already being filled by the bank, so a file on top
    // of it is how the same year lands twice.
    it('does not count a connected account as somewhere a file can go', async () => {
        accounts.current = [CHECKING, CONNECTED];

        renderStep();

        expect(await screen.findByText('Bring in your history')).toBeTruthy();
    });
});

describe('StepImportTransactions file handling', () => {
    it('sends an unreadable file to the error screen, not to the columns', async () => {
        const { container } = renderStep();

        await screen.findByText('Bring in your history');

        const input = container.querySelector(
            'input[type="file"]',
        ) as HTMLInputElement;

        fireEvent.change(input, {
            target: { files: [new File(['%PDF'], 'statement.pdf')] },
        });

        expect(await screen.findByText("We can't read that one")).toBeTruthy();
        expect(
            screen.getByText(/PDF is not supported/, { exact: false }),
        ).toBeTruthy();
        expect(parseImportFile).not.toHaveBeenCalled();
    });

    it('shows what the file holds before anything is written', async () => {
        const { container } = renderStep();

        await screen.findByText('Bring in your history');
        await chooseFile(container);
        await confirmColumns();

        expect(screen.getByText('Glovo')).toBeTruthy();
        expect(importTransactions).not.toHaveBeenCalled();
    });

    // The exit #989 gave the step, now that the step is the screen itself.
    it('lets someone with no file past the step', async () => {
        const { onComplete } = renderStep();

        fireEvent.click(await screen.findByText("I don't have one yet"));

        expect(onComplete).toHaveBeenCalled();
    });
});

describe('StepImportTransactions importing', () => {
    it('moves on once everything in the file is in', async () => {
        const { container, onComplete } = renderStep();

        await screen.findByText('Bring in your history');
        await chooseFile(container);
        await confirmColumns();
        fireEvent.click(screen.getByText('Import 2 movements'));

        await waitFor(() => expect(onComplete).toHaveBeenCalled());
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_import_completed',
            {
                transactions_imported: 2,
                transactions_unreadable: 0,
                transactions_failed: 0,
            },
        );
    });

    it('stays on the results when some rows did not make it', async () => {
        importTransactions.mockResolvedValue({
            imported: [true, false],
            errors: [
                {
                    rowNumber: 3,
                    transaction: { date: '', description: '', amount: '' },
                    error: 'Server said no',
                },
            ],
            successCount: 1,
            uncategorizedCount: 0,
        });

        const { container, onComplete } = renderStep();

        await screen.findByText('Bring in your history');
        await chooseFile(container);
        await confirmColumns();
        fireEvent.click(screen.getByText('Import 2 movements'));

        expect(await screen.findByText('Continue with 1')).toBeTruthy();
        expect(onComplete).not.toHaveBeenCalled();
    });

    // Rows the mapping had to drop never reach the import, so the count that
    // comes back is clean — and the user would still never hear about them.
    it('reports the rows the file lost on the way in', async () => {
        buildMappingReport.mockReturnValue({
            problems: [
                {
                    rowNumber: 44,
                    severity: 'skipped',
                    faults: [{ reason: 'No date', severity: 'skipped' }],
                },
                {
                    rowNumber: 45,
                    severity: 'skipped',
                    faults: [{ reason: 'No date', severity: 'skipped' }],
                },
            ],
        });

        const { container } = renderStep();

        await screen.findByText('Bring in your history');
        await chooseFile(container);
        await confirmColumns();
        fireEvent.click(screen.getByText('Import 2 movements'));

        expect(await screen.findByText('2 rows')).toBeTruthy();
        expect(screen.getByText('Rows 44–45')).toBeTruthy();
    });

    // What #988 fixed: a retry must not create the rows that already made it.
    // They are on the server now, so the duplicate check is what keeps them out.
    it('re-checks for duplicates when a failed import is retried', async () => {
        importTransactions.mockResolvedValue({
            imported: [true, false],
            errors: [
                {
                    rowNumber: 3,
                    transaction: { date: '', description: '', amount: '' },
                    error: 'Server said no',
                },
            ],
            successCount: 1,
            uncategorizedCount: 0,
        });

        const { container } = renderStep();

        await screen.findByText('Bring in your history');
        await chooseFile(container);
        await confirmColumns();
        fireEvent.click(screen.getByText('Import 2 movements'));

        fireEvent.click(await screen.findByText('Fix the file and retry'));

        await screen.findByText('Bring in your history');
        checkDuplicates.mockResolvedValue([true, false]);
        await chooseFile(container);
        await confirmColumns();

        expect(screen.getByText('Import 1 movement')).toBeTruthy();
        expect(screen.getByText(/look like duplicates/)).toBeTruthy();
    });
});
