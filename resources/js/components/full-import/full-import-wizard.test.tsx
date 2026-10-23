import { type FullImportContext, type ImportStatus } from '@/types/full-import';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { AxiosError, type AxiosResponse } from 'axios';
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

const captureEvent = vi.hoisted(() => vi.fn());
const fetchColumnSuggestions = vi.hoisted(() => vi.fn());
const ai = vi.hoisted(() => ({ enabled: false }));

vi.mock('@/lib/ai-import-mapping', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/ai-import-mapping')>()),
    fetchColumnSuggestions,
}));
const captureException = vi.hoisted(() => vi.fn());

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@sentry/react', () => ({ captureException }));

/** The properties of every event sent under that name, in order. */
function sent(name: string): Record<string, unknown>[] {
    return captureEvent.mock.calls
        .filter(([event]) => event === name)
        .map(([, properties]) => properties);
}

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
            aiImportMappingEnabled: ai.enabled,
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
        profiles: { banktrack: null, money_pro: null, generic: null },
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
        ai.enabled = false;
        api.fetchBankMatches.mockResolvedValue({});
        api.submitImport.mockResolvedValue(QUEUED);
        api.fetchImport.mockResolvedValue(QUEUED);
    });

    it('walks through the Banktrack export only for the Banktrack source', async () => {
        api.fetchImportContext.mockResolvedValue(context());
        render(<FullImportWizard variant="page" onClose={vi.fn()} />);

        await screen.findByText('Where are you coming from?');

        const help = screen
            .getByText('How to export your data from Banktrack')
            .closest('details')!;

        expect(
            within(help)
                .getAllByRole('listitem')
                .map((step) => step.textContent),
        ).toEqual([
            'In Banktrack, open the «Transacciones» module.',
            'Leave the bank and account filter empty and pick the widest period: the download only includes what you see on screen.',
            'Click «Descargar» and choose CSV or XLSX.',
            'Upload the file here as it comes: no need to open it, rename columns or delete rows.',
        ]);

        const guide = within(help).getByRole('link', {
            name: 'Read the full guide',
        });

        expect(guide).toHaveAttribute(
            'href',
            '/documentation/import-from-another-app/banktrack',
        );
        expect(guide).toHaveAttribute('target', '_blank');

        fireEvent.click(
            screen.getByRole('radio', {
                name: /Another app or my own spreadsheet/,
            }),
        );

        expect(
            screen.queryByText('How to export your data from Banktrack'),
        ).not.toBeInTheDocument();
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

        expect(sent('full_import_opened')).toEqual([{ entry: 'settings' }]);
        expect(sent('full_import_file_parsed')).toEqual([
            {
                recognized: true,
                source: 'banktrack',
                extension: 'csv',
                rows: 12,
                skipped_blank_rows: 1,
                columns_count: 20,
            },
        ]);
        expect(sent('full_import_submitted')).toEqual([
            expect.objectContaining({
                mode: 'add',
                source: 'banktrack',
                rows: 12,
                accounts_new: 4,
                accounts_mapped: 0,
                accounts_skipped: 0,
                estimated_duplicates: null,
                entry: 'settings',
            }),
        ]);
        expect(
            sent('full_import_step_viewed').map(
                (properties) => properties.step,
            ),
        ).toEqual([
            'file',
            'columns',
            'accounts',
            'categories',
            'review',
            'progress',
        ]);

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

    it('recognises a Money Pro export and imports its transfers and balances', async () => {
        api.fetchImportContext.mockResolvedValue(context());
        render(<FullImportWizard variant="page" onClose={vi.fn()} />);

        await screen.findByText('Where are you coming from?');
        fireEvent.change(screen.getByTestId('full-import-file-input'), {
            target: {
                files: [
                    new File(
                        [
                            new Uint8Array(
                                readFileSync(
                                    'resources/js/lib/__fixtures__/money-pro-sample.csv',
                                ),
                            ),
                        ],
                        'money-pro.csv',
                        { type: 'text/csv' },
                    ),
                ],
            },
        });
        await screen.findByText('Money Pro format recognized.');

        await continueTo('Check the columns');

        expect(
            screen.getByRole('combobox', { name: 'Currency of $' }),
        ).toHaveTextContent('USD');

        await continueTo('Your accounts');
        await continueTo('Your categories');
        await continueTo('Ready to import');
        fireEvent.click(
            screen.getByRole('button', { name: 'Import 5 transactions' }),
        );

        await waitFor(() => expect(api.submitImport).toHaveBeenCalled());

        const [payload, transactions, balances] =
            api.submitImport.mock.calls[0];

        expect(payload).toMatchObject({
            source: 'money_pro',
            expected_transactions: 5,
        });
        expect(
            payload.accounts.map(
                (entry: { name: string; currency_code: string }) =>
                    `${entry.name} ${entry.currency_code}`,
            ),
        ).toEqual(['Wallet EUR', 'Safe box USD', 'Savings USD']);
        expect(
            transactions
                .filter(
                    (row: { category_key: string }) =>
                        row.category_key === 'own',
                )
                .map((row: { amount: number }) => row.amount),
        ).toEqual([-75000, 75000]);
        expect(balances).toContainEqual({
            account_key: 'a2',
            date: '2018-01-30',
            balance: 800000000,
        });
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

    it('sends only the headers of a file it does not recognise', async () => {
        api.fetchImportContext.mockResolvedValue(context());
        render(<FullImportWizard variant="embedded" onClose={vi.fn()} />);

        await screen.findByText('Where are you coming from?');
        fireEvent.change(screen.getByTestId('full-import-file-input'), {
            target: {
                files: [
                    new File(
                        [
                            'Date,Amount,Memo\n2026-01-01,-5.25,Secret Coffee Shop\n',
                        ],
                        'Juana Pérez export.csv',
                        { type: 'text/csv' },
                    ),
                ],
            },
        });

        await waitFor(() =>
            expect(sent('full_import_file_parsed')).toHaveLength(1),
        );

        const [parsed] = sent('full_import_file_parsed');

        expect(parsed).toEqual({
            recognized: false,
            source: 'generic',
            extension: 'csv',
            rows: 1,
            skipped_blank_rows: 0,
            columns_count: 3,
            headers: ['Date', 'Amount', 'Memo'],
        });
        expect(JSON.stringify(captureEvent.mock.calls)).not.toMatch(
            /Secret Coffee|5\.25|Juana/,
        );
        expect(sent('full_import_opened')).toEqual([{ entry: 'onboarding' }]);
    });

    it('reads an export split into several files as one', async () => {
        const [header, ...rows] = readFileSync(
            'resources/js/lib/__fixtures__/banktrack-sample.csv',
            'utf8',
        )
            .trim()
            .split('\n');
        const part = (name: string, lines: string[]) =>
            new File([[header, ...lines].join('\n')], name, {
                type: 'text/csv',
                lastModified: 1,
            });

        api.fetchImportContext.mockResolvedValue(context());
        render(<FullImportWizard variant="page" onClose={vi.fn()} />);

        await screen.findByText('Where are you coming from?');
        fireEvent.change(screen.getByTestId('full-import-file-input'), {
            target: {
                files: [
                    part('banktrack-1.csv', rows.slice(0, 6)),
                    part('banktrack-2.csv', rows.slice(6)),
                ],
            },
        });
        await screen.findByText('Banktrack format recognized.');
        expect(screen.getByText(/^2 files · 12 transactions/)).toBeVisible();

        fireEvent.change(screen.getByTestId('full-import-file-input'), {
            target: {
                files: [
                    new File(
                        ['Date,Amount,Memo\n2026-01-01,-5,Coffee\n'],
                        'other.csv',
                        {
                            type: 'text/csv',
                        },
                    ),
                ],
            },
        });
        await screen.findByText(
            "other.csv has different columns from banktrack-1.csv, so it can't be imported as part of the same export.",
        );
        expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
        expect(sent('full_import_file_failed')).toEqual([
            { reason: 'different_headers', extension: 'csv' },
        ]);

        fireEvent.click(
            screen.getByRole('button', { name: 'Remove other.csv' }),
        );
        await continueTo('Check the columns');
        await continueTo('Your accounts');
        await continueTo('Your categories');
        await continueTo('Ready to import');
        fireEvent.click(
            screen.getByRole('button', { name: 'Import 12 transactions' }),
        );

        await waitFor(() => expect(api.submitImport).toHaveBeenCalled());

        const [payload, transactions] = api.submitImport.mock.calls[0];

        expect(payload.file_name).toBe('banktrack-1.csv, banktrack-2.csv');
        expect(payload.accounts).toHaveLength(4);
        // Banktrack's own ids go up with every row, so importing the same
        // files again is still a no-op.
        expect(
            transactions.map((row: { external_id: string }) => row.external_id),
        ).toHaveLength(12);
        expect(
            new Set(
                transactions.map(
                    (row: { external_id: string }) => row.external_id,
                ),
            ).size,
        ).toBe(12);
    });

    it('takes a new pick as the retry of a file it could not read', async () => {
        api.fetchImportContext.mockResolvedValue(context());
        render(<FullImportWizard variant="page" onClose={vi.fn()} />);

        await screen.findByText('Where are you coming from?');
        const input = screen.getByTestId('full-import-file-input');

        fireEvent.change(input, {
            target: { files: [new File(['Date,Amount\n'], 'empty.csv')] },
        });
        await screen.findByText('There are no rows in it');

        fireEvent.change(input, { target: { files: [banktrackFile()] } });
        await screen.findByText('Banktrack format recognized.');
        expect(
            screen.queryByText('There are no rows in it'),
        ).not.toBeInTheDocument();

        fireEvent.change(input, { target: { files: [banktrackFile()] } });
        await screen.findByText('Already in the list: banktrack-export.csv');
        await continueTo('Check the columns');
    });

    it('counts a file turned away without reporting it as an error', async () => {
        api.fetchImportContext.mockResolvedValue(context());
        render(<FullImportWizard variant="page" onClose={vi.fn()} />);

        await screen.findByText('Where are you coming from?');
        fireEvent.change(screen.getByTestId('full-import-file-input'), {
            target: { files: [new File(['%PDF'], 'statement.pdf')] },
        });

        await waitFor(() =>
            expect(sent('full_import_file_failed')).toEqual([
                { reason: 'unsupported_type', extension: 'pdf' },
            ]),
        );
        expect(captureException).not.toHaveBeenCalled();
    });

    it('counts a failed upload and reports it only when the server broke', async () => {
        const { ImportUploadError } = await import('@/lib/full-import-api');
        const failure = (status: number) =>
            new ImportUploadError(
                'chunks',
                new AxiosError('failed', 'ERR', undefined, undefined, {
                    status,
                    data: { message: 'Nope' },
                } as AxiosResponse),
            );

        api.submitImport.mockRejectedValueOnce(failure(500));
        await openWithBanktrackFile(context());
        await continueTo('Check the columns');
        await continueTo('Your accounts');
        await continueTo('Your categories');
        await continueTo('Ready to import');
        fireEvent.click(
            screen.getByRole('button', { name: 'Import 12 transactions' }),
        );

        await screen.findByText('Nope');
        expect(sent('full_import_upload_failed')).toEqual([
            { stage: 'chunks', status: 500, entry: 'settings' },
        ]);
        expect(captureException).toHaveBeenCalledTimes(1);
        expect(captureException.mock.calls[0][1]).toEqual({
            tags: { feature: 'full_import', stage: 'chunks' },
            extra: { entry: 'settings', source: 'banktrack', rows: 12 },
        });

        api.submitImport.mockRejectedValueOnce(failure(422));
        fireEvent.click(
            screen.getByRole('button', { name: 'Import 12 transactions' }),
        );

        await waitFor(() =>
            expect(sent('full_import_upload_failed')).toHaveLength(2),
        );
        expect(captureException).toHaveBeenCalledTimes(1);
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

describe('FullImportWizard with AI column mapping', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        ai.enabled = true;
        fetchColumnSuggestions.mockResolvedValue({
            cached: false,
            fields: {
                transaction_date: {
                    column: 'Buchungstag',
                    index: 0,
                    confidence: 0.9,
                },
                description: {
                    column: 'Verwendungszweck',
                    index: 2,
                    confidence: 0.9,
                },
                amount: { column: 'Betrag', index: 3, confidence: 0.9 },
                currency: { column: 'Waehrung', index: 4, confidence: 0.9 },
                balance: { column: null, index: null, confidence: 0.9 },
            },
        });
    });

    it('asks the AI about a file of no known app and marks the columns it placed', async () => {
        api.fetchImportContext.mockResolvedValue(context());
        render(<FullImportWizard variant="page" onClose={vi.fn()} />);

        await screen.findByText('Where are you coming from?');
        fireEvent.change(screen.getByTestId('full-import-file-input'), {
            target: {
                files: [
                    new File(
                        [
                            'Valutadatum;Buchungstag;Verwendungszweck;Betrag;Waehrung\n02.03.2026;01.03.2026;REWE Markt;-12,40;EUR\n16.03.2026;15.03.2026;Gehalt;2.400,00;EUR\n',
                        ],
                        'sparkasse.csv',
                        { type: 'text/csv' },
                    ),
                ],
            },
        });

        await waitFor(() => expect(fetchColumnSuggestions).toHaveBeenCalled());
        const [kind, columns, accountId] = fetchColumnSuggestions.mock.calls[0];

        expect(kind).toBe('transaction');
        expect(accountId).toBeUndefined();
        expect(JSON.stringify(columns)).not.toMatch(/REWE|Gehalt|12,40/);

        await continueTo('Check the columns');

        expect(await screen.findByText('Suggested by AI')).toBeInTheDocument();
        // The AI picks the booking date over the value date.
        expect(document.getElementById('full-import-date')?.textContent).toBe(
            'Buchungstag',
        );
    });

    it('leaves a known app alone', async () => {
        await openWithBanktrackFile(context());
        await continueTo('Check the columns');

        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
        expect(screen.queryByText('Suggested by AI')).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Detect columns with AI' }),
        ).not.toBeInTheDocument();
    });
});
