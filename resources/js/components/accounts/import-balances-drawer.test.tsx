import type { Account } from '@/types/account';
import type { BalanceColumnMapping } from '@/types/balance-import';
import { DateFormat } from '@/types/import';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportBalancesDrawer } from './import-balances-drawer';

const {
    fetchColumnSuggestions,
    loadBalanceImportConfig,
    saveBalanceImportConfig,
    mappingProps,
} = vi.hoisted(() => ({
    fetchColumnSuggestions: vi.fn(),
    loadBalanceImportConfig: vi.fn(),
    saveBalanceImportConfig: vi.fn(),
    mappingProps: vi.fn(),
}));

const headers = ['Datum', 'Depotwert', 'Einstand'];
const rows = [
    { Datum: '31.01.2025', Depotwert: '10.200,00', Einstand: '9.000,00' },
    { Datum: '28.02.2025', Depotwert: '10.650,00', Einstand: '9.500,00' },
];
const file = new File(['x'], 'depot.csv');

vi.mock('@/lib/file-parser', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/file-parser')>()),
    parseFile: vi.fn(async () => ({
        headers,
        data: rows,
        rowNumbers: [2, 3],
        columns: headers.map((header) =>
            rows.map((row) => row[header as keyof (typeof rows)[0]]),
        ),
        headerRowIndex: 0,
    })),
}));

vi.mock('@/lib/ai-import-mapping', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/ai-import-mapping')>()),
    fetchColumnSuggestions,
}));

vi.mock('@/lib/import-config-storage', () => ({
    loadBalanceImportConfig,
    saveBalanceImportConfig,
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));
vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'token' }));

vi.mock('@/components/accounts/synced-balance-notice', () => ({
    SyncedBalanceNotice: () => null,
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

vi.mock('./import-balances/import-balance-step-mapping', () => ({
    ImportBalanceStepMapping: (props: {
        columnMapping: BalanceColumnMapping;
        aiDetection: ReactNode;
        onNext: () => void;
    }) => {
        mappingProps(props);

        return (
            <>
                {props.aiDetection}
                <button type="button" onClick={props.onNext}>
                    leave mapping
                </button>
            </>
        );
    },
}));

vi.mock('./import-balances/import-balance-step-preview', () => ({
    ImportBalanceStepPreview: ({ onConfirm }: { onConfirm: () => void }) => (
        <button type="button" onClick={onConfirm}>
            confirm import
        </button>
    ),
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'de', aiImportMappingEnabled: true },
    }),
}));

const account = {
    id: 'account-1',
    name: 'Depot',
    type: 'investment',
    currency_code: 'EUR',
    banking_connection_id: null,
} as Account;

function shownMapping(): BalanceColumnMapping {
    return mappingProps.mock.calls.at(-1)?.[0].columnMapping;
}

async function reachMapping() {
    render(
        <ImportBalancesDrawer
            open
            onOpenChange={vi.fn()}
            account={account}
            accountId={account.id}
        />,
    );

    fireEvent.click(await screen.findByText('pick file'));
    await screen.findByText('leave mapping');
}

describe('ImportBalancesDrawer with AI column mapping', () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal('fetch', fetchMock);
        fetchMock.mockResolvedValue({
            ok: true,
            json: async () => ({ data: { id: 'balance' } }),
        });
        loadBalanceImportConfig.mockResolvedValue(null);
        fetchColumnSuggestions.mockResolvedValue({
            cached: true,
            fields: {
                balance_date: { column: 'Datum', index: 0, confidence: 0.9 },
                balance: { column: 'Depotwert', index: 1, confidence: 0.9 },
                invested_amount: {
                    column: 'Einstand',
                    index: 2,
                    confidence: 0.9,
                },
            },
        });
    });

    afterEach(() => vi.unstubAllGlobals());

    it('asks the AI for the balance columns when no stored layout fits', async () => {
        await reachMapping();

        await screen.findByText('Suggested by AI');

        expect(fetchColumnSuggestions).toHaveBeenCalledWith(
            'balance',
            expect.arrayContaining([
                { header: 'Datum', shape: 'date (dd.mm.yyyy)' },
            ]),
            'account-1',
        );
        expect(shownMapping()).toEqual({
            balance_date: 'Datum',
            balance: 'Depotwert',
            invested_amount: 'Einstand',
        });
    });

    it('saves the layout only once balances made it in', async () => {
        await reachMapping();
        await screen.findByText('Suggested by AI');

        fireEvent.click(screen.getByText('leave mapping'));
        await screen.findByText('confirm import');

        expect(saveBalanceImportConfig).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('confirm import'));

        await waitFor(() =>
            expect(saveBalanceImportConfig).toHaveBeenCalledWith('account-1', {
                columnMapping: {
                    balance_date: 'Datum',
                    balance: 'Depotwert',
                    invested_amount: 'Einstand',
                },
                dateFormat: DateFormat.DayMonthYear,
                aiSuggested: true,
            }),
        );
    });

    it('leaves no layout behind when every balance failed', async () => {
        fetchMock.mockResolvedValue({
            ok: false,
            json: async () => ({ message: 'No' }),
        });

        await reachMapping();
        fireEvent.click(screen.getByText('leave mapping'));
        fireEvent.click(await screen.findByText('confirm import'));

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(saveBalanceImportConfig).not.toHaveBeenCalled();
    });

    it('keeps a stored layout that fits without asking the AI', async () => {
        loadBalanceImportConfig.mockResolvedValue({
            columnMapping: {
                balance_date: 'Datum',
                balance: 'Depotwert',
                invested_amount: null,
            },
            dateFormat: DateFormat.DayMonthYear,
        });

        await reachMapping();

        expect(
            await screen.findByRole('button', {
                name: 'Detect columns with AI',
            }),
        ).toBeInTheDocument();
        await waitFor(() => expect(shownMapping().invested_amount).toBeNull());
        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
    });
});
