import { fixtureFile } from '@/lib/broker-imports/testing';
import documentation from '@/routes/documentation';
import type { Account } from '@/types/account';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

const { create, checkDuplicates, post, loadBrokerLinks, saveBrokerLink } =
    vi.hoisted(() => ({
        create: vi.fn(),
        checkDuplicates: vi.fn(),
        post: vi.fn(),
        loadBrokerLinks: vi.fn(),
        saveBrokerLink: vi.fn(),
    }));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { create, checkDuplicates },
}));

vi.mock('@/lib/import-config-storage', () => ({
    loadImportConfig: vi.fn(async () => null),
    saveImportConfig: vi.fn(),
    loadBrokerLinks,
    saveBrokerLink,
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

// The preview's "latest transactions" band and the overlap notice are not what
// these tests are about, so their requests are left hanging.
vi.mock('axios', () => ({
    default: { get: () => new Promise(() => {}), post },
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
    usePage: () => ({
        props: {
            locale: 'en',
            currencies: { accounts: [{ code: 'EUR' }] },
            features: { brokerImports: true },
        },
    }),
}));

const checking: Account = {
    id: 'checking-1',
    name: 'Everyday',
    bank: { id: 'bank-bbva', user_id: null, name: 'BBVA', logo: null },
    type: 'checking',
    currency_code: 'EUR',
    banking_connection_id: null,
    external_account_id: null,
    linked_at: null,
};

function renderDrawer(accounts: Account[]) {
    render(
        <ImportTransactionsDrawer
            open
            onOpenChange={vi.fn()}
            accounts={accounts}
            banks={[
                {
                    id: 'bank-tr',
                    user_id: null,
                    name: 'Trade Republic',
                    logo: null,
                },
            ]}
        />,
    );
}

function upload(name: string, file: File = fixtureFile(name)) {
    const input = document.querySelector(
        'input[type="file"]',
    ) as HTMLInputElement;

    fireEvent.change(input, { target: { files: [file] } });
}

async function reachTradeRepublicStep(accounts: Account[]) {
    renderDrawer(accounts);

    fireEvent.click(
        await screen.findByText("Upload the file and we'll create it"),
    );
    upload('trade-republic-sample.csv');

    expect(
        await screen.findByText('This is a Trade Republic export'),
    ).not.toBeNull();

    fireEvent.click(screen.getByRole('button', { name: 'Next' }));

    await screen.findByText('Trade Republic accounts');
}

describe('ImportTransactionsDrawer with broker imports on', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        loadBrokerLinks.mockResolvedValue([]);
        checkDuplicates.mockImplementation(async (_id, rows: unknown[]) =>
            rows.map(() => false),
        );
        create.mockImplementation(async (row: { description: string }) => ({
            id: row.description,
        }));
        let created = 0;
        post.mockImplementation(
            async (url: string, body: { name?: string; type?: string }) => {
                if (url.includes('/balances')) {
                    return { data: { written: 1 } };
                }

                created++;

                return {
                    data: {
                        id: `new-${created}`,
                        name: body.name,
                        type: body.type,
                        currency_code: 'EUR',
                    },
                };
            },
        );
    });

    it('offers a way on without an account below the list', async () => {
        renderDrawer([checking]);

        expect(
            await screen.findByText(
                'Coming from Trade Republic or MyInvestor without an account yet?',
            ),
        ).not.toBeNull();
    });

    it('offers that way instead of the dead end when there are no accounts', async () => {
        renderDrawer([]);

        expect(
            await screen.findByText("Upload the file and we'll create it"),
        ).not.toBeNull();
        expect(
            screen.queryByText(
                'No accounts found. Please create an account first.',
            ),
        ).toBeNull();
    });

    it('describes a Trade Republic file and skips the column mapping', async () => {
        await reachTradeRepublicStep([]);

        expect(screen.getByText('Cash balance today')).not.toBeNull();
        expect(screen.queryByText('Map Columns')).toBeNull();
    });

    it('sends a file that is no broker export back to the account step when none was picked', async () => {
        renderDrawer([checking]);

        fireEvent.click(
            await screen.findByText("Upload the file and we'll create it"),
        );
        upload(
            'bank.csv',
            new File(['Date,Amount\n2026-01-01,10\n'], 'bank.csv'),
        );

        expect(
            await screen.findByText(/Pick the account this file belongs to/),
        ).not.toBeNull();
        expect(screen.getByRole('radio')).not.toBeNull();
    });

    it('asks to create an account first when a non-broker file comes with none to pick', async () => {
        renderDrawer([]);

        fireEvent.click(
            await screen.findByText("Upload the file and we'll create it"),
        );
        upload(
            'bank.csv',
            new File(['Date,Amount\n2026-01-01,10\n'], 'bank.csv'),
        );

        expect(
            await screen.findByText(/Create the account this file belongs to/),
        ).not.toBeNull();
    });

    it('warns when the cash goes to an account at another bank', async () => {
        renderDrawer([checking]);

        fireEvent.click(await screen.findByRole('radio'));
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        upload('trade-republic-sample.csv');
        await screen.findByText('This is a Trade Republic export');
        fireEvent.click(screen.getByRole('button', { name: 'Next' }));

        expect(await screen.findByText(/which is at BBVA/)).not.toBeNull();
    });

    it('creates both accounts on confirm and imports the movements with their ids and balances', async () => {
        await reachTradeRepublicStep([]);

        // Picking "create" only happens on confirm: nothing is posted yet.
        expect(post).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));

        // Nothing to compare against before the account exists.
        expect(await screen.findByText('Cash today')).not.toBeNull();
        expect(checkDuplicates).not.toHaveBeenCalled();

        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Import 22 transactions',
            }),
        );

        await waitFor(() => expect(create).toHaveBeenCalledTimes(22));

        expect(
            post.mock.calls
                .filter(([url]) => String(url).includes('/settings/accounts'))
                .map(([, body]) => body.name),
        ).toEqual(['Trade Republic', 'Trade Republic · Portfolio']);
        expect(
            create.mock.calls.every(
                ([row]) =>
                    row.account_id === 'new-1' &&
                    typeof row.external_transaction_id === 'string',
            ),
        ).toBe(true);

        await waitFor(() =>
            expect(
                post.mock.calls.filter(([url]) =>
                    String(url).includes('/balances'),
                ),
            ).toHaveLength(2),
        );
    });

    it('flags the movements an existing account already holds', async () => {
        checkDuplicates.mockImplementation(async (_id, rows: unknown[]) =>
            rows.map(() => true),
        );
        const tradeRepublicCash: Account = {
            ...checking,
            id: 'tr-cash',
            name: 'Trade Republic',
            bank: {
                id: 'bank-tr',
                user_id: null,
                name: 'Trade Republic',
                logo: null,
            },
        };

        await reachTradeRepublicStep([tradeRepublicCash]);
        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));

        expect(
            await screen.findByText('22 rows are already in this account'),
        ).not.toBeNull();

        const [accountId, rows] = checkDuplicates.mock.calls[0];

        expect(accountId).toBe('tr-cash');
        expect(
            (rows as { external_id?: string }[]).every(
                (row) => row.external_id,
            ),
        ).toBe(true);
        expect(
            within(document.body)
                .getByRole('button', { name: /Import 0 transactions/ })
                .hasAttribute('disabled'),
        ).toBe(true);
    });

    it('imports only the portfolio when the cash account is connected to the bank', async () => {
        const connectedCash: Account = {
            ...checking,
            id: 'tr-connected',
            name: 'Trade Republic',
            bank: {
                id: 'bank-tr',
                user_id: null,
                name: 'Trade Republic',
                logo: null,
            },
            banking_connection_id: 'connection-1',
        };

        await reachTradeRepublicStep([connectedCash]);

        expect(
            screen.getByText(/so only the portfolio will be imported/),
        ).not.toBeNull();
        // The sync owns the balance: nothing to type.
        expect(screen.queryByText('Cash balance today')).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Import portfolio history',
            }),
        );

        await waitFor(() =>
            expect(
                post.mock.calls
                    .map(([url]) => String(url))
                    .filter((url) => url.includes('/balances')),
            ).toEqual([expect.stringContaining('new-1')]),
        );
        expect(
            post.mock.calls
                .filter(([url]) => String(url).includes('/settings/accounts'))
                .map(([, body]) => body.name),
        ).toEqual(['Trade Republic · Portfolio']);
        expect(create).not.toHaveBeenCalled();
        expect(checkDuplicates).not.toHaveBeenCalled();
    });

    describe('remembering the import', () => {
        const renamedCash: Account = {
            ...checking,
            id: 'cash',
            name: 'Spending',
            bank: null,
        };
        const renamedPortfolio: Account = {
            ...checking,
            id: 'portfolio',
            name: 'My ETFs',
            bank: null,
            type: 'investment',
        };

        it('remembers the accounts it made on the cash account', async () => {
            await reachTradeRepublicStep([]);

            fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Import 22 transactions',
                }),
            );

            await waitFor(() => expect(saveBrokerLink).toHaveBeenCalled());
            expect(saveBrokerLink).toHaveBeenCalledWith(
                'new-1',
                expect.objectContaining({
                    broker: 'trade_republic',
                    portfolioAccountId: 'new-2',
                }),
            );
        });

        it('shows the accounts of last time, with a way to change them', async () => {
            loadBrokerLinks.mockResolvedValue([
                {
                    accountId: renamedCash.id,
                    config: {
                        broker: 'trade_republic',
                        portfolioAccountId: renamedPortfolio.id,
                        lastImportedAt: '2026-02-01T10:00:00.000Z',
                        lastValueMinor: 130_000,
                        lastValueDate: '2026-02-01',
                        lastInvestedMinor: 100_000,
                    },
                },
            ]);

            await reachTradeRepublicStep([renamedCash, renamedPortfolio]);

            expect(screen.getByText('Last import: Feb 1, 2026')).not.toBeNull();
            expect(screen.getByText('Spending')).not.toBeNull();
            expect(screen.getByText('My ETFs')).not.toBeNull();
            expect(screen.getByText(/Last time you entered/)).not.toBeNull();
            expect(screen.getByText('since last time')).not.toBeNull();
            expect(screen.queryByRole('combobox')).toBeNull();
            // Both accounts exist: what the import overwrites is still told.
            expect(
                screen.getAllByText(/Its daily balances from .* are replaced/),
            ).toHaveLength(2);

            fireEvent.click(screen.getByRole('button', { name: 'Change' }));

            expect(screen.queryByText(/Last import:/)).toBeNull();
            expect(screen.getAllByRole('combobox')).toHaveLength(2);
        });
    });

    describe('MyInvestor', () => {
        async function reachMyInvestorStep(
            fixture: string,
            notice: string,
            heading: string,
            accounts: Account[] = [],
        ) {
            renderDrawer(accounts);

            fireEvent.click(
                await screen.findByText("Upload the file and we'll create it"),
            );
            upload(fixture);

            expect(await screen.findByText(notice)).not.toBeNull();

            fireEvent.click(screen.getByRole('button', { name: 'Next' }));

            await screen.findByText(heading);
        }

        it("links the guide's MyInvestor section from the file notice and the step", async () => {
            renderDrawer([]);

            fireEvent.click(
                await screen.findByText("Upload the file and we'll create it"),
            );
            upload('myinvestor-cash.xlsx');

            const guide = `${documentation.show.url('transactions/import-from-a-broker')}#myinvestor`;
            const notice = await screen.findByRole('link', {
                name: 'Read the full guide',
            });

            expect(notice.getAttribute('href')).toBe(guide);
            expect(notice.getAttribute('target')).toBe('_blank');

            fireEvent.click(screen.getByRole('button', { name: 'Next' }));
            await screen.findByText('MyInvestor current account');

            expect(
                screen
                    .getByRole('link', { name: 'What this import does' })
                    .getAttribute('href'),
            ).toBe(guide);
        });

        it('imports the current-account xlsx into a new savings account, balance from the file', async () => {
            await reachMyInvestorStep(
                'myinvestor-cash.xlsx',
                'This is a MyInvestor current-account export',
                'MyInvestor current account',
            );

            // The file says the balance: nothing to type.
            expect(document.querySelector('#broker-cash-balance')).toBeNull();
            expect(screen.getByText(/The file's last balance/)).not.toBeNull();

            fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Import 5 transactions',
                }),
            );

            await waitFor(() => expect(create).toHaveBeenCalledTimes(5));
            expect(
                post.mock.calls
                    .filter(([url]) =>
                        String(url).includes('/settings/accounts'),
                    )
                    .map(([, body]) => [body.name, body.type]),
            ).toEqual([['MyInvestor', 'savings']]);
            await waitFor(() =>
                expect(
                    post.mock.calls.filter(([url]) =>
                        String(url).includes('/balances'),
                    ),
                ).toHaveLength(1),
            );
        });

        it('has nothing to import into a current account connected to the bank', async () => {
            const connected: Account = {
                ...checking,
                id: 'mi-connected',
                name: 'MyInvestor',
                bank: {
                    id: 'bank-mi',
                    user_id: null,
                    name: 'MyInvestor',
                    logo: null,
                },
                type: 'savings',
                banking_connection_id: 'connection-1',
            };

            await reachMyInvestorStep(
                'myinvestor-cash.xlsx',
                'This is a MyInvestor current-account export',
                'MyInvestor current account',
                [connected],
            );

            expect(
                screen.getByText(/this file has nothing to import into it/),
            ).not.toBeNull();
            expect(
                screen
                    .getByRole('button', { name: 'Preview' })
                    .hasAttribute('disabled'),
            ).toBe(true);
        });

        it('lets the balance be typed when the CSV has none', async () => {
            await reachMyInvestorStep(
                'myinvestor-cash.csv',
                'This is a MyInvestor current-account export',
                'MyInvestor current account',
            );

            expect(
                document.querySelector('#broker-cash-balance'),
            ).not.toBeNull();
        });

        it('writes only the portfolio history for the securities file', async () => {
            await reachMyInvestorStep(
                'myinvestor-salida.xls',
                'MyInvestor securities operations',
                'MyInvestor portfolio',
            );

            expect(
                screen.getByText(/1 pension-plan contribution has no ISIN/),
            ).not.toBeNull();

            fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Import portfolio history',
                }),
            );

            await waitFor(() =>
                expect(
                    post.mock.calls.filter(([url]) =>
                        String(url).includes('/balances'),
                    ),
                ).toHaveLength(1),
            );
            expect(
                post.mock.calls
                    .filter(([url]) =>
                        String(url).includes('/settings/accounts'),
                    )
                    .map(([, body]) => [body.name, body.type]),
            ).toEqual([['MyInvestor · Portfolio', 'investment']]);
            expect(create).not.toHaveBeenCalled();
            expect(checkDuplicates).not.toHaveBeenCalled();
        });

        it('picks the portfolio account of the last import again', async () => {
            const portfolio: Account = {
                ...checking,
                id: 'mi-portfolio',
                name: 'MyInvestor · Portfolio',
                bank: null,
                type: 'investment',
            };

            await reachMyInvestorStep(
                'myinvestor-salida.xls',
                'MyInvestor securities operations',
                'MyInvestor portfolio',
                [checking, portfolio],
            );

            expect(screen.getByRole('combobox').textContent).toContain(
                'MyInvestor · Portfolio',
            );

            fireEvent.click(screen.getByRole('button', { name: 'Preview' }));
            fireEvent.click(
                await screen.findByRole('button', {
                    name: 'Import portfolio history',
                }),
            );

            await waitFor(() =>
                expect(post.mock.calls.map(([url]) => String(url))).toEqual([
                    expect.stringContaining('mi-portfolio'),
                ]),
            );
        });
    });
});
