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
    ])('refuses %s, with a warning and no throw', (_name, destination) => {
        beginWidgetGesture(undefined, { canNavigate: true });

        expect(wm.open(destination)).toBe(false);
        expect(router.visit).not.toHaveBeenCalled();
        expect(console.warn).toHaveBeenCalledWith(
            expect.stringContaining('wm.open('),
        );
    });
});
