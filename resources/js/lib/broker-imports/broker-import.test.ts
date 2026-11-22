import { store as storeAccount } from '@/actions/App/Http/Controllers/Settings/AccountController';
import {
    readBrokerImport,
    runBrokerImport,
} from '@/lib/broker-imports/broker-import';
import {
    defaultMyInvestorSecuritiesPlan,
    type MyInvestorSecuritiesImport,
} from '@/lib/broker-imports/myinvestor-import';
import { parseMyInvestorSecurities } from '@/lib/broker-imports/myinvestor-securities';
import { fixtureFile, fixtureGrid } from '@/lib/broker-imports/testing';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, captureEvent, captureException } = vi.hoisted(() => ({
    post: vi.fn(),
    captureEvent: vi.fn(),
    captureException: vi.fn(),
}));

vi.mock('axios', () => ({
    default: { post },
    isAxiosError: (error: unknown) =>
        Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError),
}));
vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@sentry/react', () => ({ captureException }));
vi.mock('@/lib/leave-page', () => ({ isLeavingPage: () => false }));

const TODAY = '2026-10-12';

const RUN_OPTIONS = {
    rows: [],
    accounts: [],
    categories: [],
    banks: [],
    automationRules: [],
};

async function securitiesImport(): Promise<MyInvestorSecuritiesImport> {
    return {
        kind: 'myinvestor_securities',
        file: parseMyInvestorSecurities(
            await fixtureGrid('myinvestor-salida.xls'),
        ),
        today: TODAY,
        plan: { ...defaultMyInvestorSecuritiesPlan([]), currentValue: null },
    };
}

/** An axios-like failure of a request to `url`. */
function requestError(url: string, status: number): Error {
    return Object.assign(new Error('Request failed'), {
        isAxiosError: true,
        config: { url },
        response: { status },
    });
}

describe('broker import telemetry', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.spyOn(console, 'error').mockImplementation(() => {});
        post.mockImplementation(async (url: string) =>
            url.includes('/balances')
                ? { data: { written: 1 } }
                : {
                      data: {
                          id: 'new-1',
                          name: 'MyInvestor',
                          type: 'investment',
                          currency_code: 'EUR',
                          banking_connection_id: null,
                      },
                  },
        );
    });

    it('tells PostHog a recognised broker file was read, with its extension only', async () => {
        await readBrokerImport(
            fixtureFile('myinvestor-salida.xls'),
            [],
            null,
            async () => [],
            TODAY,
        );

        expect(captureEvent).toHaveBeenCalledWith(
            'broker_import_file_detected',
            {
                kind: 'myinvestor_securities',
                extension: 'xls',
                reimport: false,
            },
        );
    });

    it('says nothing about a file that is not a broker export', async () => {
        const csv = new File(['date,amount\n2026-01-01,10'], 'bank.csv');

        expect(await readBrokerImport(csv, [], null)).toBeNull();
        expect(captureEvent).not.toHaveBeenCalled();
    });

    it('tracks a completed run with counts and booleans only', async () => {
        const onAccountCreated = vi.fn();

        await runBrokerImport(await securitiesImport(), {
            ...RUN_OPTIONS,
            onAccountCreated,
        });

        expect(onAccountCreated).toHaveBeenCalledWith(
            'portfolio',
            expect.objectContaining({ id: 'new-1' }),
        );
        expect(captureEvent).toHaveBeenCalledWith('broker_import_completed', {
            kind: 'myinvestor_securities',
            rows: 0,
            success_count: 0,
            failure_count: 0,
            uncategorized_count: 0,
            portfolio: 'imported',
            cash_connected: false,
            balances_failed: false,
            accounts_created_cash: 0,
            accounts_created_portfolio: 1,
            reimport: false,
        });
        expect(captureException).not.toHaveBeenCalled();
    });

    it('reports failed balances in the completed event and to Sentry', async () => {
        post.mockImplementation(async (url: string) => {
            if (url.includes('/balances')) {
                throw requestError(url, 500);
            }

            return {
                data: { id: 'new-1', type: 'investment', currency_code: 'EUR' },
            };
        });

        const { balancesFailed } = await runBrokerImport(
            await securitiesImport(),
            RUN_OPTIONS,
        );

        expect(balancesFailed).toBe(true);
        expect(captureEvent).toHaveBeenCalledWith(
            'broker_import_completed',
            expect.objectContaining({ balances_failed: true }),
        );
        expect(captureException).toHaveBeenCalledWith(
            expect.any(Error),
            expect.objectContaining({
                tags: {
                    feature: 'broker_import',
                    kind: 'myinvestor_securities',
                    stage: 'balances',
                    role: 'portfolio',
                },
            }),
        );
    });

    it('tracks and reports a run that threw while making an account, then rethrows', async () => {
        const error = requestError(storeAccount.url(), 500);
        post.mockRejectedValue(error);

        await expect(
            runBrokerImport(await securitiesImport(), RUN_OPTIONS),
        ).rejects.toBe(error);

        const extra = {
            rows: 0,
            accounts_created_cash: 0,
            accounts_created_portfolio: 0,
            reimport: false,
        };

        expect(captureEvent).toHaveBeenCalledWith('broker_import_failed', {
            kind: 'myinvestor_securities',
            stage: 'accounts',
            status: 500,
            ...extra,
        });
        expect(captureException).toHaveBeenCalledWith(error, {
            tags: {
                feature: 'broker_import',
                kind: 'myinvestor_securities',
                stage: 'accounts',
            },
            extra: { status: 500, ...extra },
        });
    });

    it('tracks a refused run in PostHog but keeps it out of Sentry', async () => {
        post.mockRejectedValue(requestError(storeAccount.url(), 422));

        await expect(
            runBrokerImport(await securitiesImport(), RUN_OPTIONS),
        ).rejects.toThrow();

        expect(captureEvent).toHaveBeenCalledWith(
            'broker_import_failed',
            expect.objectContaining({ stage: 'accounts', status: 422 }),
        );
        expect(captureException).not.toHaveBeenCalled();
    });
});
