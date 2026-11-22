import {
    brokerBank,
    isBankConnected,
    writeBalanceSeries,
} from '@/lib/broker-imports/broker-accounts';
import { isTradeRepublicBank } from '@/lib/broker-imports/trade-republic-import';
import { type Account } from '@/types/account';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { post, captureException } = vi.hoisted(() => ({
    post: vi.fn(),
    captureException: vi.fn(),
}));

vi.mock('axios', () => ({ default: { post } }));
vi.mock('@sentry/react', () => ({ captureException }));
vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

const tradeRepublicBank = {
    id: 'bank-tr',
    user_id: null,
    name: 'Trade Republic',
    logo: null,
};

function account(id: string, changes: Partial<Account> = {}): Account {
    return {
        id,
        name: id,
        bank: null,
        type: 'checking',
        currency_code: 'EUR',
        banking_connection_id: null,
        external_account_id: null,
        linked_at: null,
        ...changes,
    };
}

const BALANCES = [{ balance_date: '2026-03-02', balance: 100 }];

describe('writeBalanceSeries', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it("writes every account's series even when an earlier one fails, and reports the failure", async () => {
        post.mockImplementation(async (url: string) => {
            if (url.includes('cash')) {
                throw Object.assign(new Error('Server said no'), {
                    response: { status: 422 },
                });
            }

            return { data: { written: 1 } };
        });

        const written = await writeBalanceSeries('trade_republic', [
            {
                role: 'cash',
                account: account('cash', { banking_connection_id: 'c-1' }),
                balances: BALANCES,
            },
            {
                role: 'portfolio',
                account: account('portfolio'),
                balances: BALANCES,
            },
        ]);

        expect(written).toEqual({ allWritten: false, sent: 2 });
        expect(post.mock.calls.map(([url]) => String(url))).toEqual([
            expect.stringContaining('cash'),
            expect.stringContaining('portfolio'),
        ]);
        // Even a refusal: the import planned the series, so it is our bug.
        expect(captureException).toHaveBeenCalledTimes(1);
        expect(captureException).toHaveBeenCalledWith(expect.any(Error), {
            tags: {
                feature: 'broker_import',
                kind: 'trade_republic',
                stage: 'balances',
                role: 'cash',
            },
            extra: {
                status: 422,
                account_type: 'checking',
                connected: true,
                balances: 1,
            },
        });
    });

    it('is true when every series was written', async () => {
        post.mockResolvedValue({ data: { written: 1 } });

        expect(
            await writeBalanceSeries('trade_republic', [
                { role: 'cash', account: account('cash'), balances: BALANCES },
                {
                    role: 'portfolio',
                    account: account('portfolio'),
                    balances: BALANCES,
                },
            ]),
        ).toEqual({ allWritten: true, sent: 2 });
        expect(captureException).not.toHaveBeenCalled();
    });
});

describe('isBankConnected', () => {
    it('is true only for an account with a banking connection', () => {
        expect(
            isBankConnected(account('a', { banking_connection_id: 'c-1' })),
        ).toBe(true);
        expect(isBankConnected(account('a'))).toBe(false);
        expect(isBankConnected(undefined)).toBe(false);
    });
});

describe('brokerBank', () => {
    it("prefers the banks list, else finds the broker's bank on an account", () => {
        const fromList = { ...tradeRepublicBank, id: 'bank-listed' };
        const connected = account('tr', { bank: tradeRepublicBank });

        expect(
            brokerBank([fromList], [connected], isTradeRepublicBank)?.id,
        ).toBe('bank-listed');
        expect(brokerBank([], [connected], isTradeRepublicBank)?.id).toBe(
            'bank-tr',
        );
        expect(brokerBank([], [account('other')], isTradeRepublicBank)).toBe(
            null,
        );
    });
});
