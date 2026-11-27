import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { type ForecastRestOfMonthData } from '@/types/forecast-entry';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ForecastRestOfMonthCard } from './forecast-rest-of-month-card';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@inertiajs/react', async () => ({
    Link: (await import('@/lib/inertia-link.fixture')).FixtureLink,
}));

function rest(
    overrides: Partial<ForecastRestOfMonthData> = {},
): ForecastRestOfMonthData {
    return {
        month: '2026-11',
        currencyCode: 'EUR',
        lines: [{ name: 'Salary', date: '2026-11-28', amount: 245000 }],
        estimates: [
            { name: 'Groceries', kind: 'expense', amount: -800 },
            { name: 'Leisure', kind: 'expense', amount: -500 },
        ],
        endBalance: 2342000,
        ...overrides,
    };
}

function renderCard(data: ForecastRestOfMonthData) {
    return render(
        <PrivacyModeProvider>
            <ForecastRestOfMonthCard rest={data} />
        </PrivacyModeProvider>,
    );
}

function amountNextTo(label: string): string {
    return screen.getByText(label).nextElementSibling?.textContent ?? '';
}

describe('ForecastRestOfMonthCard', () => {
    it('lists what is still to come, the estimates and the month close', () => {
        renderCard(rest());

        expect(
            screen.getByText('What is left of November'),
        ).toBeInTheDocument();
        expect(screen.getByText('forecasted')).toBeInTheDocument();
        expect(amountNextTo('Salary (Nov 28)')).toMatch(/^\+.*2,450\.00/);
        expect(amountNextTo('Groceries and Leisure')).toMatch(/-.*13\.00/);
        expect(amountNextTo('Balance at the end of the month')).toMatch(
            /23,420\.00/,
        );
        expect(
            screen.getByRole('link', { name: 'See the next 12 months' }),
        ).toHaveAttribute('href', '/forecast');
    });

    it('shows income in the success color and spending in the foreground', () => {
        renderCard(rest());

        expect(
            screen.getByText('Salary (Nov 28)').nextElementSibling
                ?.firstElementChild,
        ).toHaveClass('text-success');
        expect(
            screen.getByText('Groceries and Leisure').nextElementSibling
                ?.firstElementChild,
        ).not.toHaveClass('text-success');
    });

    it('sums the lines beyond the first few in one row', () => {
        renderCard(
            rest({
                lines: ['A', 'B', 'C', 'D', 'E'].map((name, index) => ({
                    name,
                    date: `2026-11-2${index}`,
                    amount: -1000,
                })),
                estimates: [],
            }),
        );

        expect(screen.getByText('A (Nov 20)')).toBeInTheDocument();
        expect(screen.getByText('C (Nov 22)')).toBeInTheDocument();
        expect(screen.queryByText('D (Nov 23)')).not.toBeInTheDocument();
        expect(amountNextTo('2 more planned')).toMatch(/-.*20\.00/);
    });

    it('names the first estimates and counts the rest, other spending included', () => {
        renderCard(
            rest({
                lines: [],
                estimates: [
                    { name: null, kind: 'expense', amount: -3000 },
                    { name: 'Groceries', kind: 'expense', amount: -2000 },
                    { name: 'Leisure', kind: 'expense', amount: -1000 },
                ],
            }),
        );

        expect(amountNextTo('Other spending, Groceries, and 1 more')).toMatch(
            /-.*60\.00/,
        );
    });

    it('lists the estimated income apart from the estimated spend', () => {
        renderCard(
            rest({
                lines: [],
                estimates: [
                    { name: 'Salary', kind: 'income', amount: 245000 },
                    { name: 'Groceries', kind: 'expense', amount: -2000 },
                    { name: null, kind: 'income', amount: 1000 },
                ],
            }),
        );

        expect(amountNextTo('Salary and Other income')).toMatch(/2,460\.00/);
        expect(amountNextTo('Groceries')).toMatch(/-.*20\.00/);
    });
});
