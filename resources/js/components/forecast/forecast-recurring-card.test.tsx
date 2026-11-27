import {
    type ForecastRecurringCharge,
    type ForecastRecurringCharges,
} from '@/types/forecast';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ForecastRecurringCard } from './forecast-recurring-card';

const router = vi.hoisted(() => ({ visit: vi.fn(), reload: vi.fn() }));

vi.mock('sonner', () => ({ toast: { error: vi.fn() } }));

vi.mock('@inertiajs/react', () => ({ router }));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-format-amount', () => ({
    useFormatAmount: () => ({
        formatAmount: (amount: number, currency: string) =>
            `${currency} ${amount / 100}`,
    }),
}));

function charge(
    overrides: Partial<ForecastRecurringCharge> &
        Pick<ForecastRecurringCharge, 'key'>,
): ForecastRecurringCharge {
    return {
        name: overrides.key,
        accountId: 'checking',
        accountName: 'Checking',
        currencyCode: 'EUR',
        frequency: 'monthly',
        amount: 1599,
        monthly: 1599,
        yearly: 19188,
        lastChargedOn: '2026-11-05',
        canDrop: true,
        drop: null,
        kept: false,
        ...overrides,
    };
}

function data(
    charges: ForecastRecurringCharge[],
    totals = [{ currencyCode: 'EUR', monthly: 16099 }],
): ForecastRecurringCharges {
    return { charges, totals };
}

/** The last request sent: its url, method and data. */
function lastVisit(): { url: string; method: string; data: unknown } {
    const [url, options] = router.visit.mock.calls.at(-1) ?? [];

    return { url, method: options?.method, data: options?.data };
}

function rows(): HTMLElement[] {
    return [
        ...document.querySelectorAll<HTMLElement>(
            '[data-test="recurring-charge"]',
        ),
    ];
}

function byTest(name: string): HTMLElement | null {
    return document.querySelector<HTMLElement>(`[data-test="${name}"]`);
}

function getByTest(name: string): HTMLElement {
    const element = byTest(name);

    if (element === null) {
        throw new Error(`No [data-test="${name}"]`);
    }

    return element;
}

beforeEach(() => {
    router.visit.mockReset();
    router.reload.mockReset();
});

describe('ForecastRecurringCard', () => {
    it('shows a skeleton until the deferred data comes', () => {
        render(<ForecastRecurringCard recurringCharges={undefined} />);

        expect(getByTest('recurring-loading')).toBeTruthy();
        expect(screen.getByText('What you pay every month')).toBeTruthy();
    });

    it('says when nothing repeats', () => {
        render(<ForecastRecurringCard recurringCharges={data([], [])} />);

        expect(screen.getByText('Nothing repeats yet')).toBeTruthy();
        expect(rows()).toHaveLength(0);
    });

    it('titles the card with the monthly total of every currency', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data(
                    [charge({ key: 'netflix' })],
                    [
                        { currencyCode: 'EUR', monthly: 16099 },
                        { currencyCode: 'USD', monthly: 1000 },
                    ],
                )}
            />,
        );

        expect(
            screen.getByText(
                'What you pay every month · EUR 160.99 + USD 10/month',
            ),
        ).toBeTruthy();
    });

    it('reads each charge: amount, frequency, account, last charge and yearly total', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([
                    charge({
                        key: 'insurance',
                        name: 'Mapfre',
                        frequency: 'quarterly',
                        amount: 30000,
                        monthly: 10000,
                        yearly: 120000,
                        lastChargedOn: '2026-11-12',
                    }),
                ])}
            />,
        );

        const row = rows()[0];
        expect(within(row).getByText('Mapfre')).toBeTruthy();
        expect(within(row).getByText('-30000')).toBeTruthy();
        expect(
            within(row).getByText(
                'Quarterly · ≈ EUR 100 a month · Checking · last charged on Nov 12, 2026 · ≈ EUR 1200 a year',
            ),
        ).toBeTruthy();
    });

    it('shows the top five, and every charge after "See all"', () => {
        const charges = Array.from({ length: 7 }, (_, index) =>
            charge({ key: `charge-${index}` }),
        );
        render(<ForecastRecurringCard recurringCharges={data(charges)} />);

        expect(rows()).toHaveLength(5);

        fireEvent.click(screen.getByText('See all (7)'));
        expect(rows()).toHaveLength(7);

        fireEvent.click(screen.getByText('Show less'));
        expect(rows()).toHaveLength(5);
    });

    it('sends "I’m dropping it" and "Keep" with the charge key, then reloads the card', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([charge({ key: 'netflix' })])}
            />,
        );

        fireEvent.click(getByTest('recurring-drop'));
        expect(lastVisit()).toEqual({
            url: '/forecast/recurring-charges/drops',
            method: 'post',
            data: { key: 'netflix' },
        });

        act(() => router.visit.mock.calls[0][1].onSuccess());
        expect(router.reload).toHaveBeenCalledWith(
            expect.objectContaining({ only: ['recurringCharges'] }),
        );

        fireEvent.click(getByTest('recurring-keep'));
        expect(lastVisit()).toEqual({
            url: '/forecast/recurring-charges/keeps',
            method: 'post',
            data: { key: 'netflix' },
        });
    });

    it('disables the actions while one is on its way', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([charge({ key: 'netflix' })])}
            />,
        );

        fireEvent.click(getByTest('recurring-drop'));
        act(() => router.visit.mock.calls[0][1].onStart());

        expect(
            (getByTest('recurring-keep') as HTMLButtonElement).disabled,
        ).toBe(true);
    });

    it('names the charge each action is about, for screen readers', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([
                    charge({ key: 'netflix', name: 'Netflix' }),
                ])}
            />,
        );

        expect(getByTest('recurring-drop').getAttribute('aria-label')).toBe(
            'I’m dropping Netflix',
        );
        expect(getByTest('recurring-keep').getAttribute('aria-label')).toBe(
            'Keep Netflix',
        );
    });

    it('offers only "Keep" for a charge the forecast does not count as spend', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([
                    charge({ key: 'refund', canDrop: false }),
                ])}
            />,
        );

        expect(byTest('recurring-drop')).toBeNull();
        expect(getByTest('recurring-keep')).toBeTruthy();
    });

    it('marks a dropped charge, and undoes it', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([
                    charge({
                        key: 'netflix',
                        drop: {
                            id: 'drop-1',
                            month: '2026-12',
                            reduction: 1599,
                        },
                    }),
                ])}
            />,
        );

        expect(getByTest('recurring-dropped').textContent).toBe(
            'Dropped from December 2026',
        );
        expect(byTest('recurring-drop')).toBeNull();
        expect(byTest('recurring-keep')).toBeNull();

        fireEvent.click(getByTest('recurring-dropped-undo'));
        expect(lastVisit()).toEqual({
            url: '/forecast/recurring-charges/drops/drop-1',
            method: 'delete',
            data: {},
        });
    });

    it('says when dropping a charge took less off the forecast than it costs', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([
                    charge({
                        key: 'netflix',
                        drop: {
                            id: 'drop-1',
                            month: '2026-12',
                            reduction: 500,
                        },
                    }),
                ])}
            />,
        );

        expect(getByTest('recurring-dropped').textContent).toBe(
            'Dropped from December 2026 · the forecast only had EUR 5 left',
        );
    });

    it('marks a kept charge, and undoes it', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([charge({ key: 'gym', kept: true })])}
            />,
        );

        expect(getByTest('recurring-kept')).toBeTruthy();
        expect(byTest('recurring-drop')).toBeNull();

        fireEvent.click(getByTest('recurring-kept-undo'));
        expect(lastVisit()).toEqual({
            url: '/forecast/recurring-charges/keeps',
            method: 'delete',
            data: { key: 'gym' },
        });
    });

    it('keeps showing the last data while the page reloads after an action', () => {
        const { rerender } = render(
            <ForecastRecurringCard
                recurringCharges={data([charge({ key: 'netflix' })])}
            />,
        );

        rerender(<ForecastRecurringCard recurringCharges={undefined} />);

        expect(rows()).toHaveLength(1);
        expect(byTest('recurring-loading')).toBeNull();
    });
});
