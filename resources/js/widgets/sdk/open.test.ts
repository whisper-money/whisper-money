import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { beginWidgetGesture, endWidgetGesture } from './gesture';
import { wm } from './index';
import { resolveDestination } from './open';

vi.mock('@inertiajs/react', () => ({ router: { visit: vi.fn() } }));

const ACCOUNT = '0198f0a1-7c3e-7a10-9b2c-1d2e3f4a5b6c';
const BUDGET = '0198f0a1-7c3e-7a10-9b2c-6c5b4a3f2e1d';
const CATEGORY = '0198f0a1-7c3e-7a10-9b2c-aaaaaaaaaaaa';
const LABEL = '0198f0a1-7c3e-7a10-9b2c-bbbbbbbbbbbb';

function urlOf(destination: unknown): string {
    const resolved = resolveDestination(destination);

    if (!('url' in resolved)) {
        throw new Error(resolved.error);
    }

    return decodeURIComponent(resolved.url);
}

describe('wm.open', () => {
    beforeEach(() => {
        vi.mocked(router.visit).mockClear();
        vi.spyOn(console, 'warn').mockImplementation(() => {});
    });

    afterEach(() => {
        endWidgetGesture();
        vi.restoreAllMocks();
    });

    it('opens an account, a budget and a filtered transactions list', () => {
        expect(urlOf({ account: ACCOUNT })).toBe(`/accounts/${ACCOUNT}`);
        expect(urlOf({ budget: BUDGET })).toBe(`/budgets/${BUDGET}`);
        expect(
            urlOf({
                transactions: {
                    categoryIds: [CATEGORY, 'uncategorized'],
                    accountIds: [ACCOUNT],
                    labelIds: [LABEL],
                    dateFrom: '2026-10-01',
                    dateTo: '2026-10-31',
                    search: 'Mercadona',
                },
            }),
        ).toBe(
            `/transactions?category_ids[]=${CATEGORY}&category_ids[]=uncategorized` +
                `&account_ids[]=${ACCOUNT}&label_ids[]=${LABEL}` +
                '&date_from=2026-10-01&date_to=2026-10-31&search=Mercadona',
        );
        expect(urlOf({ transactions: { labelIds: [], search: ' ' } })).toBe(
            '/transactions',
        );
    });

    it('takes every filter the transactions page takes, in any combination', () => {
        expect(
            urlOf({
                transactions: {
                    categoryTypes: ['savings', 'investment'],
                    accountIds: [ACCOUNT],
                    creditorName: 'Mercadona',
                    debtorName: 'Ana',
                    categorySource: 'ai',
                },
            }),
        ).toBe(
            '/transactions?category_types[]=savings&category_types[]=investment' +
                `&account_ids[]=${ACCOUNT}&creditor_name=Mercadona&debtor_name=Ana` +
                '&category_source=ai',
        );
        expect(
            urlOf({
                transactions: {
                    cashflowBlock: 'fixed',
                    dateFrom: '2026-11-01',
                    dateTo: '2026-11-30',
                    labelIds: [LABEL],
                },
            }),
        ).toBe(
            '/transactions?cashflow_block=fixed&date_from=2026-11-01' +
                `&date_to=2026-11-30&label_ids[]=${LABEL}`,
        );
        expect(
            urlOf({
                transactions: {
                    categoryTypes: [],
                    creditorName: ' ',
                    debtorName: '',
                },
            }),
        ).toBe('/transactions');
    });

    it('builds the query the page test opens /transactions with', () => {
        // tests/Feature/TransactionFilterTest.php visits this exact URL and
        // checks each filter lands among the page's applied filters.
        expect(
            urlOf({
                transactions: {
                    categoryTypes: ['savings', 'investment'],
                    dateFrom: '2026-11-01',
                    dateTo: '2026-11-30',
                    amountMin: -5000,
                    amountMax: -1234,
                    currency: 'EUR',
                    creditorName: 'Mercadona',
                },
            }),
        ).toBe(
            '/transactions?category_types[]=savings&category_types[]=investment' +
                '&date_from=2026-11-01&date_to=2026-11-30' +
                '&amount_min=-50.00&amount_max=-12.34&creditor_name=Mercadona',
        );
    });

    it.each([['fixed'], ['variable'], ['general'], ['saved'], ['invested']])(
        'opens the %s cashflow block over a period',
        (block) => {
            expect(
                urlOf({
                    transactions: {
                        dateFrom: '2026-11-01',
                        dateTo: '2026-11-30',
                        cashflowBlock: block,
                    },
                }),
            ).toBe(
                `/transactions?date_from=2026-11-01&date_to=2026-11-30&cashflow_block=${block}`,
            );
        },
    );

    it('turns amounts in minor units into the major units the list filters by', () => {
        expect(
            urlOf({
                transactions: {
                    amountMin: -5000,
                    amountMax: -1234,
                    currency: 'EUR',
                },
            }),
        ).toBe('/transactions?amount_min=-50.00&amount_max=-12.34');
        expect(
            urlOf({ transactions: { currency: 'cop', amountMin: 25000 } }),
        ).toBe('/transactions?amount_min=25000');
        expect(urlOf({ transactions: { amountMax: 5, currency: 'KWD' } })).toBe(
            '/transactions?amount_max=0.005',
        );
        expect(urlOf({ transactions: { currency: 'EUR' } })).toBe(
            '/transactions',
        );
    });

    it('visits the page from a click where the host lets it navigate', () => {
        beginWidgetGesture(undefined, { canNavigate: true });

        expect(wm.open({ budget: BUDGET })).toBe(true);
        expect(router.visit).toHaveBeenCalledWith(`/budgets/${BUDGET}`);
    });

    it('refuses outside a click', () => {
        expect(wm.open({ budget: BUDGET })).toBe(false);

        beginWidgetGesture(undefined, { canNavigate: true });
        endWidgetGesture();

        expect(wm.open({ budget: BUDGET })).toBe(false);
        expect(router.visit).not.toHaveBeenCalled();
        expect(console.warn).toHaveBeenCalledWith(
            expect.stringContaining('only works inside a click'),
        );
    });

    it('refuses a click the page faked', () => {
        beginWidgetGesture(
            { nativeEvent: { isTrusted: false } },
            { canNavigate: true },
        );

        expect(wm.open({ budget: BUDGET })).toBe(false);
        expect(router.visit).not.toHaveBeenCalled();
    });

    it('refuses where the host does not enable navigation (editing, preview)', () => {
        beginWidgetGesture();

        expect(wm.open({ account: ACCOUNT })).toBe(false);

        endWidgetGesture();
        beginWidgetGesture(undefined, { canNavigate: false });

        expect(wm.open({ account: ACCOUNT })).toBe(false);
        expect(router.visit).not.toHaveBeenCalled();
        expect(console.warn).toHaveBeenCalledWith(
            expect.stringContaining('being edited'),
        );
    });

    it('says why a cashflow block or an amount is refused', () => {
        expect(
            resolveDestination({ transactions: { cashflowBlock: 'fixed' } }),
        ).toEqual({
            error: expect.stringContaining('both dateFrom and dateTo'),
        });
        expect(
            resolveDestination({ transactions: { amountMin: -5000 } }),
        ).toEqual({ error: expect.stringContaining('currency') });
    });

    it.each([
        ['nothing', undefined],
        ['a URL', 'https://evil.test'],
        ['an empty object', {}],
        ['an array', [{ account: ACCOUNT }]],
        ['two destinations', { account: ACCOUNT, budget: BUDGET }],
        ['an unknown destination', { category: CATEGORY }],
        ['an inherited key', { toString: ACCOUNT }],
        ['a numeric id', { account: 42 }],
        ['a name for an id', { budget: 'Ocio' }],
        ['a path in the id', { account: `${ACCOUNT}/../../admin` }],
        ['transactions without filters object', { transactions: CATEGORY }],
        ['an unknown filter', { transactions: { url: '/admin' } }],
        [
            'ids that are not a list',
            { transactions: { categoryIds: CATEGORY } },
        ],
        ['a numeric id in a list', { transactions: { labelIds: [1] } }],
        ['a bad date', { transactions: { dateFrom: '2026-02-30' } }],
        [
            'a date with a time',
            { transactions: { dateTo: '2026-10-01T10:00' } },
        ],
        [
            'dates the wrong way round',
            { transactions: { dateFrom: '2026-10-31', dateTo: '2026-10-01' } },
        ],
        ['a search that is not text', { transactions: { search: 42 } }],
        ['a search too long', { transactions: { search: 'x'.repeat(201) } }],
        [
            'a category type that does not exist',
            { transactions: { categoryTypes: ['savings', 'shopping'] } },
        ],
        [
            'category types that are not a list',
            { transactions: { categoryTypes: 'savings' } },
        ],
        [
            'an unknown cashflow block',
            {
                transactions: {
                    cashflowBlock: 'rent',
                    dateFrom: '2026-11-01',
                    dateTo: '2026-11-30',
                },
            },
        ],
        [
            'a cashflow block without dates',
            { transactions: { cashflowBlock: 'fixed' } },
        ],
        [
            'a cashflow block with only one date',
            {
                transactions: {
                    cashflowBlock: 'variable',
                    dateFrom: '2026-11-01',
                },
            },
        ],
        [
            'an amount without its currency',
            { transactions: { amountMin: -5000 } },
        ],
        [
            'an amount in major units',
            { transactions: { amountMin: -50.5, currency: 'EUR' } },
        ],
        [
            'an amount written as text',
            { transactions: { amountMax: '100', currency: 'EUR' } },
        ],
        [
            'an amount with a made-up currency',
            { transactions: { amountMax: 100, currency: 'euro' } },
        ],
        [
            'amounts the wrong way round',
            {
                transactions: {
                    amountMin: -100,
                    amountMax: -5000,
                    currency: 'EUR',
                },
            },
        ],
        [
            'a creditor name too long',
            { transactions: { creditorName: 'x'.repeat(256) } },
        ],
        ['a debtor name that is not text', { transactions: { debtorName: 7 } }],
        [
            'a category source the page cannot show',
            { transactions: { categorySource: 'manual' } },
        ],
    ])('refuses %s, with a warning and no throw', (_name, destination) => {
        beginWidgetGesture(undefined, { canNavigate: true });

        expect(wm.open(destination)).toBe(false);
        expect(router.visit).not.toHaveBeenCalled();
        expect(console.warn).toHaveBeenCalledWith(
            expect.stringContaining('wm.open('),
        );
    });
});
