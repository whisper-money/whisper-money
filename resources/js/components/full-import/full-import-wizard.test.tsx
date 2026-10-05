import { type FullImportContext, type ImportStatus } from '@/types/full-import';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { FullImportWizard } from './full-import-wizard';

const api = vi.hoisted(() => ({
    checkExistingTransactions: vi.fn(),
    fetchImportContext: vi.fn(),
    fetchBankMatches: vi.fn(),
    fetchImport: vi.fn(),
    submitImport: vi.fn(),
}));

vi.mock('@/lib/full-import-api', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/full-import-api')>()),
    ...api,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            auth: { user: { currency_code: 'EUR' } },
            currencies: {
                accounts: [
                    { code: 'EUR', name: 'Euro' },
                    { code: 'USD', name: 'US Dollar' },
                ],
                profile: [],
                decimals: { EUR: 2, USD: 2 },
            },
            locale: 'en-US',
        },
    }),
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
}));

function context(
    overrides: Partial<FullImportContext> = {},
): FullImportContext {
    return {
        inOnboarding: false,
        aiAvailable: true,
        running: null,
        accounts: [],
        mappableAccountIds: [],
        categories: [
            {
                id: 'groceries',
                name: 'Supermercado',
                icon: 'ShoppingBasket',
                color: 'red',
                type: 'expense',
                cashflow_direction: 'hidden',
                parent_id: null,
            },
        ],
        defaultCategoryNames: [],
        transferTargets: {
            own: {
                category_id: 'own-account',
                name: 'Cuenta propia',
                icon: 'ArrowRightLeft',
                color: 'blue',
            },
            ignored: {
                category_id: null,
                name: 'Otras transferencias',
                icon: 'Split',
                color: 'stone',
            },
        },
        profiles: { banktrack: null, generic: null },
        ...overrides,
    };
}

const QUEUED: ImportStatus = {
    id: 'import-1',
    source: 'banktrack',
    mode: 'add',
    status: 'queued',
    file_name: 'banktrack-export.csv',
    error: null,
    created_at: null,
    finished_at: null,
    undone_at: null,
    stats: { stage: 'queued' },
};

function banktrackFile(): File {
    return new File(
        [
            new Uint8Array(
                readFileSync(
                    'resources/js/lib/__fixtures__/banktrack-sample.csv',
                ),
            ),
        ],
        'banktrack-export.csv',
        { type: 'text/csv' },
    );
}

/** Open the wizard on a context and drop the Banktrack fixture into it. */
async function openWithBanktrackFile(loaded: FullImportContext) {
    api.fetchImportContext.mockResolvedValue(loaded);

    render(<FullImportWizard variant="page" onClose={vi.fn()} />);

    await screen.findByText('Where are you coming from?');
    fireEvent.change(screen.getByTestId('full-import-file-input'), {
        target: { files: [banktrackFile()] },
    });
    await screen.findByText('Banktrack format recognized.');
}

async function continueTo(title: string) {
    const button = screen.getByRole('button', { name: 'Continue' });

    await waitFor(() => expect(button).toBeEnabled());
    fireEvent.click(button);
    await screen.findByText(title);
}

describe('FullImportWizard', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        api.fetchBankMatches.mockResolvedValue({});
        api.submitImport.mockResolvedValue(QUEUED);
        api.fetchImport.mockResolvedValue(QUEUED);
    });

    it('reads a Banktrack export and sends the plan with its rows', async () => {
        await openWithBanktrackFile(context());
        await continueTo('Check the columns');
        await continueTo('Your accounts');
        await continueTo('Your categories');

        expect(screen.getByText('Marked as «Ignored»')).toBeInTheDocument();
        expect(screen.getByText('Traspasos Propios')).toBeInTheDocument();

        await continueTo('Ready to import');
        fireEvent.click(
            screen.getByRole('button', { name: 'Import 12 transactions' }),
        );

        await waitFor(() => expect(api.submitImport).toHaveBeenCalled());

        const [payload, transactions, balances] =
            api.submitImport.mock.calls[0];

        expect(payload).toMatchObject({
            source: 'banktrack',
            file_name: 'banktrack-export.csv',
            mode: 'add',
            expected_transactions: 12,
            expected_balances: balances.length,
        });
        expect(payload.accounts).toHaveLength(4);
        expect(
            payload.accounts.every(
                (entry: { action: string }) => entry.action === 'create',
            ),
        ).toBe(true);
        expect(payload.categories).toContainEqual({
            key: 'own',
            action: 'match',
            category_id: 'own-account',
        });
        expect(payload.categories).toContainEqual(
            expect.objectContaining({
                key: 'ignored',
                action: 'create',
                type: 'transfer',
            }),
        );
        expect(payload.categories).toContainEqual(
            expect.objectContaining({
                action: 'match',
                category_id: 'groceries',
            }),
        );
        expect(transactions).toHaveLength(12);
        expect(
            transactions.find(
                (row: { external_id: string }) => row.external_id === 'bt-0008',
            ),
        ).toMatchObject({ amount: -15868, category_key: 'ignored' });

        await screen.findByText('Importing your data');
    });

    it('asks what to do with existing data and requires the wipe to be confirmed', async () => {
        await openWithBanktrackFile(
            context({
                accounts: [
                    {
                        id: 'manual-1',
                        name: 'BBVA Conjunta',
                        type: 'checking',
                        currency_code: 'EUR',
                        connected: false,
                        archived: false,
                        transactions_count: 3,
                        bank: null,
                    },
                ],
                mappableAccountIds: ['manual-1'],
            }),
        );
        await continueTo('You already have data here');

        fireEvent.click(screen.getByText('Start from scratch'));
        await continueTo('Check the columns');
        await continueTo('Your accounts');
        await continueTo('Your categories');
        await continueTo('Ready to import');

        const importButton = screen.getByRole('button', {
            name: 'Import 12 transactions',
        });

        expect(importButton).toBeDisabled();

        const warning = screen
            .getByText('I understand my manual accounts will be deleted')
            .closest('label') as HTMLElement;
        fireEvent.click(within(warning).getByRole('checkbox'));

        expect(importButton).toBeEnabled();
    });

    it('warns when a file account goes into an account in another currency', async () => {
        await openWithBanktrackFile(
            context({
                accounts: [
                    {
                        id: 'wise-usd',
                        name: 'Wise Personal',
                        type: 'checking',
                        currency_code: 'USD',
                        connected: false,
                        archived: false,
                        transactions_count: 0,
                        bank: null,
                    },
                ],
                mappableAccountIds: ['wise-usd'],
            }),
        );
        await continueTo('You already have data here');
        await continueTo('Check the columns');
        await continueTo('Your accounts');

        expect(
            screen.getByText(
                "This account is in USD; the file's transactions are in EUR and are imported as they are, without conversion.",
            ),
        ).toBeInTheDocument();
    });

    it('estimates on Review what a mapped account already holds', async () => {
        api.checkExistingTransactions.mockImplementation(
            async (_accountId: string, rows: unknown[]) => rows.map(() => true),
        );
        await openWithBanktrackFile(
            context({
                accounts: [
                    {
                        id: 'wise-own',
                        name: 'Wise Personal',
                        type: 'checking',
                        currency_code: 'EUR',
                        connected: false,
                        archived: false,
                        transactions_count: 4,
                        bank: null,
                    },
                ],
                mappableAccountIds: ['wise-own'],
            }),
        );
        await continueTo('You already have data here');
        await continueTo('Check the columns');
        await continueTo('Your accounts');
        await continueTo('Your categories');
        await continueTo('Ready to import');

        expect(
            await screen.findByRole('button', {
                name: 'Import 8 transactions',
            }),
        ).toBeEnabled();
        expect(
            screen.getByText('8 new, about 4 already there'),
        ).toBeInTheDocument();
        expect(api.checkExistingTransactions).toHaveBeenCalledWith(
            'wise-own',
            expect.any(Array),
        );
    });

    it('warns before leaving while the rows are still uploading', async () => {
        let finishUpload: (status: ImportStatus) => void = () => undefined;
        api.submitImport.mockImplementation(
            () =>
                new Promise<ImportStatus>((resolve) => {
                    finishUpload = resolve;
                }),
        );
        await openWithBanktrackFile(context());
        await continueTo('Check the columns');
        await continueTo('Your accounts');
        await continueTo('Your categories');
        await continueTo('Ready to import');

        fireEvent.click(
            screen.getByRole('button', { name: 'Import 12 transactions' }),
        );
        await screen.findByText('Importing your data');

        const leaving = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(leaving);
        expect(leaving.defaultPrevented).toBe(true);

        await act(async () => finishUpload(QUEUED));

        const leavingLater = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(leavingLater);
        expect(leavingLater.defaultPrevented).toBe(false);
    });

    it('jumps to the progress of an import that is already running', async () => {
        api.fetchImportContext.mockResolvedValue(
            context({ running: 'import-1' }),
        );

        render(<FullImportWizard variant="page" onClose={vi.fn()} />);

        await screen.findByText('Importing your data');
        expect(api.fetchImport).toHaveBeenCalledWith('import-1');
    });
});
