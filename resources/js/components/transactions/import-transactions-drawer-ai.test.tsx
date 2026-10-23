import type { ParsedImportFile } from '@/lib/transaction-import';
import type { Account } from '@/types/account';
import { DateFormat, type ColumnMapping } from '@/types/import';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

const {
    fetchColumnSuggestions,
    applyStoredImportConfig,
    importTransactions,
    saveImportConfig,
    captureEvent,
    mappingProps,
} = vi.hoisted(() => ({
    fetchColumnSuggestions: vi.fn(),
    applyStoredImportConfig: vi.fn(),
    importTransactions: vi.fn(),
    saveImportConfig: vi.fn(),
    captureEvent: vi.fn(),
    mappingProps: vi.fn(),
}));

const headers = ['Buchungstag', 'Valutadatum', 'Verwendungszweck', 'Betrag'];
const rows = [
    {
        Buchungstag: '01.03.2025',
        Valutadatum: '02.03.2025',
        Verwendungszweck: 'REWE Markt',
        Betrag: '-12,40',
    },
    {
        Buchungstag: '15.03.2025',
        Valutadatum: '16.03.2025',
        Verwendungszweck: 'Gehalt',
        Betrag: '2.400,00',
    },
];
const file = new File(['x'], 'sparkasse.csv');

/** What the header guess makes of a Sparkasse export: the value date, and no amount. */
function guessed(): ParsedImportFile {
    return {
        file,
        rows,
        rowNumbers: [2, 3],
        headers,
        columnOptions: headers.map((header) => ({
            value: header,
            label: header,
            examples: [],
        })),
        mapping: {
            transaction_date: 'Valutadatum',
            description: 'Verwendungszweck',
            amount: null,
            currency: null,
            balance: null,
            creditor_name: null,
            debtor_name: null,
            transaction_type: null,
            expense_types: [],
        },
        dateFormat: DateFormat.DayMonthYear,
        dateFormatDetected: true,
        dateFormatAmbiguous: false,
    };
}

const suggestion = {
    cached: false,
    fields: {
        transaction_date: { column: 'Buchungstag', index: 0, confidence: 0.95 },
        description: { column: 'Verwendungszweck', index: 2, confidence: 0.9 },
        amount: { column: 'Betrag', index: 3, confidence: 0.95 },
        currency: { column: null, index: null, confidence: 0.9 },
        balance: { column: null, index: null, confidence: 0.9 },
        creditor_name: { column: null, index: null, confidence: 0.9 },
        debtor_name: { column: null, index: null, confidence: 0.9 },
        transaction_type: { column: null, index: null, confidence: 0.9 },
    },
};

vi.mock('@/lib/ai-import-mapping', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/ai-import-mapping')>()),
    fetchColumnSuggestions,
}));

vi.mock('@/lib/transaction-import', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/transaction-import')>()),
    parseImportFile: vi.fn(async () => guessed()),
    applyStoredImportConfig,
    importTransactions,
}));

vi.mock('@/lib/import-config-storage', () => ({ saveImportConfig }));
vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: {
        checkDuplicates: vi.fn(async () => [false, false]),
    },
}));

vi.mock('@/components/import-step-upload', () => ({
    ImportStepUpload: ({
        onFileSelect,
        onNext,
    }: {
        onFileSelect: (file: File) => Promise<void>;
        onNext: () => void;
    }) => (
        <button
            type="button"
            onClick={async () => {
                await onFileSelect(file);
                onNext();
            }}
        >
            pick file
        </button>
    ),
}));

// The mapping step shows the AI control it is handed, and stands in for the
// user changing the amount column.
vi.mock('./import-step-mapping', () => ({
    ImportStepMapping: (props: {
        columnMapping: ColumnMapping;
        aiDetection: ReactNode;
        onNext: () => void;
        onMappingChange: (field: keyof ColumnMapping, value: string) => void;
    }) => {
        mappingProps(props);

        return (
            <>
                {props.aiDetection}
                <button
                    type="button"
                    onClick={() =>
                        props.onMappingChange('amount', 'Valutadatum')
                    }
                >
                    change amount
                </button>
                <button type="button" onClick={props.onNext}>
                    leave mapping
                </button>
            </>
        );
    },
}));

vi.mock('./import-step-preview', () => ({
    ImportStepPreview: ({ onConfirm }: { onConfirm: () => void }) => (
        <button type="button" onClick={onConfirm}>
            confirm import
        </button>
    ),
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => ({
        props: {
            locale: 'de',
            features: { calculateBalancesOnImport: false },
            currencies: { accounts: [{ code: 'EUR' }] },
            aiImportMappingEnabled: true,
        },
    }),
}));

const account: Account = {
    id: 'account-1',
    name: 'Girokonto',
    bank: null,
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

/** The column mapping the mapping step was last rendered with. */
function shownMapping(): ColumnMapping {
    return mappingProps.mock.calls.at(-1)?.[0].columnMapping;
}

async function reachMapping() {
    render(
        <ImportTransactionsDrawer
            open
            onOpenChange={vi.fn()}
            accounts={[account]}
        />,
    );

    fireEvent.click(await screen.findByRole('radio'));
    fireEvent.click(screen.getByRole('button', { name: 'Next' }));
    fireEvent.click(await screen.findByText('pick file'));
    await screen.findByText('leave mapping');
}

async function importIt() {
    fireEvent.click(screen.getByText('leave mapping'));
    fireEvent.click(await screen.findByText('confirm import'));
    await waitFor(() => expect(importTransactions).toHaveBeenCalled());
}

describe('ImportTransactionsDrawer with AI column mapping', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        applyStoredImportConfig.mockResolvedValue(null);
        fetchColumnSuggestions.mockResolvedValue(suggestion);
        importTransactions.mockResolvedValue({
            imported: [true, true],
            errors: [],
            successCount: 2,
            uncategorizedCount: 0,
        });
    });

    it('asks the AI when no stored layout fits, sending only headers and shapes', async () => {
        await reachMapping();

        await screen.findByText('Suggested by AI');

        expect(fetchColumnSuggestions).toHaveBeenCalledWith(
            'transaction',
            [
                { header: 'Buchungstag', shape: 'date (dd.mm.yyyy)' },
                { header: 'Valutadatum', shape: 'date (dd.mm.yyyy)' },
                {
                    header: 'Verwendungszweck',
                    shape: 'text, avg 8 chars, mostly distinct values',
                },
                {
                    header: 'Betrag',
                    shape: 'number, 2 decimals with ",", mixed signs',
                },
            ],
            'account-1',
        );
        expect(shownMapping()).toMatchObject({
            transaction_date: 'Buchungstag',
            amount: 'Betrag',
        });
    });

    it('saves the layout only once the import brought rows in, flagged as the suggestion', async () => {
        await reachMapping();
        await screen.findByText('Suggested by AI');

        fireEvent.click(screen.getByText('leave mapping'));
        await screen.findByText('confirm import');

        expect(saveImportConfig).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('confirm import'));

        await waitFor(() =>
            expect(saveImportConfig).toHaveBeenCalledWith(
                'account-1',
                expect.objectContaining({
                    aiSuggested: true,
                    columnMapping: expect.objectContaining({
                        amount: 'Betrag',
                    }),
                }),
            ),
        );
        expect(captureEvent).toHaveBeenCalledWith(
            'import_ai_mapping_imported',
            expect.objectContaining({
                entry: 'account_drawer',
                changed_fields: 0,
                cached: false,
            }),
        );
    });

    it('turns the badge back into the button once a field is changed, and saves it as the user’s', async () => {
        await reachMapping();
        await screen.findByText('Suggested by AI');

        fireEvent.click(screen.getByText('change amount'));

        expect(
            await screen.findByRole('button', {
                name: 'Detect columns with AI',
            }),
        ).toBeInTheDocument();

        await importIt();

        await waitFor(() =>
            expect(saveImportConfig).toHaveBeenCalledWith(
                'account-1',
                expect.objectContaining({ aiSuggested: false }),
            ),
        );
    });

    it('saves nothing when the import brought no rows in', async () => {
        importTransactions.mockResolvedValue({
            imported: [false, false],
            errors: [{ rowNumber: 2 }, { rowNumber: 3 }],
            successCount: 0,
            uncategorizedCount: 0,
        });

        await reachMapping();
        await importIt();

        expect(saveImportConfig).not.toHaveBeenCalled();
    });

    it('keeps a stored layout that fits, and asks the AI only from the button', async () => {
        applyStoredImportConfig.mockResolvedValue({
            ...guessed(),
            mapping: { ...guessed().mapping, amount: 'Betrag' },
            aiSuggested: false,
        });

        await reachMapping();
        const button = await screen.findByRole('button', {
            name: 'Detect columns with AI',
        });

        expect(fetchColumnSuggestions).not.toHaveBeenCalled();

        fireEvent.click(button);

        await screen.findByText('Suggested by AI');
        expect(shownMapping().transaction_date).toBe('Buchungstag');
    });

    it('shows the badge for a stored layout saved as the suggestion', async () => {
        applyStoredImportConfig.mockResolvedValue({
            ...guessed(),
            mapping: {
                ...guessed().mapping,
                transaction_date: 'Buchungstag',
                amount: 'Betrag',
            },
            aiSuggested: true,
        });

        await reachMapping();

        expect(await screen.findByText('Suggested by AI')).toBeInTheDocument();
        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
    });

    it('keeps the header guess silently when the AI has no answer', async () => {
        fetchColumnSuggestions.mockResolvedValue(null);

        await reachMapping();

        expect(
            await screen.findByRole('button', {
                name: 'Detect columns with AI',
            }),
        ).toBeInTheDocument();
        expect(shownMapping().transaction_date).toBe('Valutadatum');
    });
});
