import { fixtureFile } from '@/lib/broker-imports/testing';
import type { Account } from '@/types/account';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ImportBalancesDrawer } from './import-balances-drawer';

const { brokerImports, fetchColumnSuggestions } = vi.hoisted(() => ({
    brokerImports: { enabled: true },
    fetchColumnSuggestions: vi.fn(),
}));

vi.mock('@/lib/ai-import-mapping', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/ai-import-mapping')>()),
    fetchColumnSuggestions,
}));

vi.mock('@/lib/import-config-storage', () => ({
    loadBalanceImportConfig: vi.fn(async () => null),
    saveBalanceImportConfig: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

vi.mock('@/components/accounts/synced-balance-notice', () => ({
    SyncedBalanceNotice: () => null,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            locale: 'es',
            features: { brokerImports: brokerImports.enabled },
        },
    }),
}));

const portfolio = {
    id: 'portfolio-1',
    name: 'Portfolio',
    type: 'investment',
    currency_code: 'EUR',
    banking_connection_id: null,
} as Account;

function uploadOrdersList() {
    render(
        <ImportBalancesDrawer
            open
            onOpenChange={vi.fn()}
            account={portfolio}
            accountId={portfolio.id}
        />,
    );

    fireEvent.change(
        document.querySelector('input[type="file"]') as HTMLInputElement,
        { target: { files: [fixtureFile('myinvestor-orders.xlsx')] } },
    );
}

describe("ImportBalancesDrawer with MyInvestor's orders list", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        brokerImports.enabled = true;
        fetchColumnSuggestions.mockResolvedValue({ cached: true, fields: {} });
    });

    it('says which file to download instead of mapping balance columns', async () => {
        uploadOrdersList();

        expect(
            await screen.findByText(/This is MyInvestor's orders list/),
        ).not.toBeNull();
        expect(
            screen.getByRole('link', {
                name: 'Which MyInvestor file to download',
            }),
        ).not.toBeNull();
        expect(
            (screen.getByRole('button', { name: 'Next' }) as HTMLButtonElement)
                .disabled,
        ).toBe(true);
        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
    });

    it('leaves the file to the balance mapping with broker imports off', async () => {
        brokerImports.enabled = false;

        uploadOrdersList();

        expect(
            await screen.findByText('myinvestor-orders.xlsx'),
        ).not.toBeNull();
        expect(
            screen.queryByText(/This is MyInvestor's orders list/),
        ).toBeNull();
    });
});
