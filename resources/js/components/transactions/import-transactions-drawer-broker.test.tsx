import { fixtureFile } from '@/lib/broker-imports/testing';
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

const { create, checkDuplicates, post } = vi.hoisted(() => ({
    create: vi.fn(),
    checkDuplicates: vi.fn(),
    post: vi.fn(),
}));

vi.mock('@/services/transaction-sync', () => ({
    transactionSyncService: { create, checkDuplicates },
}));

vi.mock('@/lib/import-config-storage', () => ({
    loadImportConfig: vi.fn(async () => null),
    saveImportConfig: vi.fn(),
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
            await screen.findByText(/Pick the account it belongs to/),
        ).not.toBeNull();
        expect(screen.getByRole('radio')).not.toBeNull();
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
});
