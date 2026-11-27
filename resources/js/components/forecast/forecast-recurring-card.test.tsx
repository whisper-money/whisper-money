import {
    type ForecastRecurringCharge,
    type ForecastRecurringCharges,
} from '@/types/forecast';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ForecastRecurringCard } from './forecast-recurring-card';

const router = vi.hoisted(() => ({ post: vi.fn(), delete: vi.fn() }));

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
    router.post.mockReset();
    router.delete.mockReset();
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
                'Quarterly · Checking · last charged on Nov 12, 2026 · ≈ EUR 1200 a year',
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

    it('posts "I’m dropping it" and "Keep" with the charge key', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([charge({ key: 'netflix' })])}
            />,
        );

        fireEvent.click(getByTest('recurring-drop'));
        expect(router.post).toHaveBeenCalledWith(
            '/forecast/recurring-charges/drops',
            { key: 'netflix' },
            expect.objectContaining({ preserveScroll: true }),
        );

        act(() => router.post.mock.calls[0][2].onFinish());
        fireEvent.click(getByTest('recurring-keep'));
        expect(router.post).toHaveBeenLastCalledWith(
            '/forecast/recurring-charges/keeps',
            { key: 'netflix' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    it('disables the actions while one is on its way', () => {
        render(
            <ForecastRecurringCard
                recurringCharges={data([charge({ key: 'netflix' })])}
            />,
        );

        fireEvent.click(getByTest('recurring-drop'));

        expect(
            (getByTest('recurring-keep') as HTMLButtonElement).disabled,
        ).toBe(true);
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
                        drop: { id: 'drop-1', month: '2026-12' },
                    }),
                ])}
            />,
        );

        expect(getByTest('recurring-dropped').textContent).toBe(
            'Dropped from December 2026',
        );
        expect(byTest('recurring-drop')).toBeNull();
        expect(byTest('recurring-keep')).toBeNull();

        fireEvent.click(getByTest('recurring-undo-drop'));
        expect(router.delete).toHaveBeenCalledWith(
            '/forecast/recurring-charges/drops/drop-1',
            expect.objectContaining({ preserveScroll: true }),
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

        fireEvent.click(getByTest('recurring-undo-keep'));
        expect(router.delete).toHaveBeenCalledWith(
            '/forecast/recurring-charges/keeps',
            expect.objectContaining({ data: { key: 'gym' } }),
        );
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
