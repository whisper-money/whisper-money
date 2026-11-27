import {
    type ForecastAnnualSuggestion,
    type ForecastReview,
    type ForecastReviewEstimate,
} from '@/types/forecast';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ForecastReviewDialog } from './forecast-review-dialog';

const router = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router,
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-format-amount', () => ({
    useFormatAmount: () => ({
        formatAmount: (amount: number) => `€${amount / 100}`,
        formatAmountNumber: (amount: number) => `${amount / 100}`,
    }),
}));

vi.mock('@/components/ui/amount-input', () => ({
    AmountInput: ({
        id,
        onChange,
    }: {
        id: string;
        onChange: (value: number) => void;
    }) => (
        <input
            id={id}
            onChange={(event) => onChange(Number(event.target.value))}
        />
    ),
}));

const insurance: ForecastAnnualSuggestion = {
    key: 'annual|checking|car insurance',
    name: 'Car insurance',
    accountId: 'checking',
    accountName: 'Checking',
    currencyCode: 'EUR',
    nextDate: '2027-04-10',
    amount: -64000,
    evidence: [
        { date: '2025-04-12', amount: -61200 },
        { date: '2026-04-10', amount: -64000 },
    ],
};

function estimate(
    overrides: Partial<ForecastReviewEstimate>,
): ForecastReviewEstimate {
    return {
        accountId: 'checking',
        accountName: 'Checking',
        currencyCode: 'EUR',
        categoryId: 'groceries',
        name: 'Groceries',
        kind: 'expense',
        amount: 40000,
        basis: 'median',
        budgetAmount: null,
        ...overrides,
    };
}

const review: ForecastReview = {
    accountId: 'checking',
    uncategorized: null,
    estimates: {
        month: '2026-12',
        currencyCode: 'EUR',
        income: [
            estimate({
                categoryId: 'salary',
                name: 'Salary',
                kind: 'income',
                amount: 250000,
            }),
            estimate({
                categoryId: null,
                name: null,
                kind: 'income',
                amount: 10000,
            }),
        ],
        expenses: [
            estimate({
                categoryId: 'housing',
                name: 'Housing',
                amount: 85000,
            }),
            estimate({
                categoryId: 'leisure',
                name: 'Leisure',
                amount: 30000,
                basis: 'budget',
                budgetAmount: 30000,
            }),
            estimate({ categoryId: null, name: null, amount: 12000 }),
        ],
        transfers: [
            {
                key: 'transfer-estimate:checking:savings',
                fromAccountName: 'Checking',
                toAccountName: 'Savings',
                amount: 50000,
                currencyCode: 'EUR',
            },
        ],
        totals: { income: 260000, expenses: 127000, net: 133000 },
        unconvertedCurrencies: [],
        unusableBudgets: [],
    },
    annual: [insurance],
    accounts: [
        { id: 'checking', name: 'Checking', currencyCode: 'EUR' },
        { id: 'savings', name: 'Savings', currencyCode: 'EUR' },
    ],
};

function renderDialog(
    props: Partial<Parameters<typeof ForecastReviewDialog>[0]> = {},
) {
    const onOpenChange = vi.fn();
    const onSaved = vi.fn();

    render(
        <ForecastReviewDialog
            open
            onOpenChange={onOpenChange}
            onSaved={onSaved}
            isPro
            review={review}
            defaultAccountId="checking"
            {...props}
        />,
    );

    return { onOpenChange, onSaved };
}

function rowNames(section: 'income' | 'expenses'): string[] {
    const element = document.querySelector(
        `[data-test="forecast-review-section-${section}"]`,
    ) as HTMLElement;

    return Array.from(
        element.querySelectorAll<HTMLElement>(
            '[data-test="forecast-review-estimate"]',
        ),
    ).map((row) => row.querySelector('.font-medium')?.textContent ?? '');
}

function postedAnswers(): Record<string, unknown> {
    return router.post.mock.calls[0][1];
}

beforeEach(() => {
    router.post.mockReset();
    router.reload.mockClear();
});

describe('the uncategorized notice', () => {
    const withNotice: ForecastReview = {
        ...review,
        uncategorized: {
            count: 14,
            monthlyAmount: 32000,
            currencyCode: 'EUR',
            from: '2026-08-01',
            to: '2026-10-31',
            accountIds: ['checking'],
        },
    };

    it('comes before step 1, with a link to those transactions', () => {
        renderDialog({ review: withNotice });

        expect(
            screen.getByText(
                'You have 14 uncategorized transactions in the last 3 months (€320/month).',
            ),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('This is how we have estimated your month'),
        ).not.toBeInTheDocument();

        const link = screen.getByRole('link', { name: 'Categorize them' });
        const url = new URL(link.getAttribute('href') ?? '', 'https://x.test');
        expect(url.pathname).toBe('/transactions');
        expect(Object.fromEntries(url.searchParams)).toEqual({
            category_ids: 'uncategorized',
            date_from: '2026-08-01',
            date_to: '2026-10-31',
            account_ids: 'checking',
        });
    });

    it('filters to every account reviewed in the "All accounts" view', () => {
        renderDialog({
            review: {
                ...withNotice,
                accountId: null,
                uncategorized: {
                    ...withNotice.uncategorized!,
                    accountIds: ['checking', 'savings'],
                },
            },
        });

        const link = screen.getByRole('link', { name: 'Categorize them' });
        expect(
            new URL(
                link.getAttribute('href') ?? '',
                'https://x.test',
            ).searchParams.get('account_ids'),
        ).toBe('checking,savings');
    });

    it('goes on to step 1 with "Continue anyway"', () => {
        renderDialog({ review: withNotice });

        fireEvent.click(
            screen.getByRole('button', { name: 'Continue anyway' }),
        );

        expect(
            screen.getByText('This is how we have estimated your month'),
        ).toBeInTheDocument();
    });

    it('is not shown again on the way back from step 2', () => {
        renderDialog({ review: withNotice });

        fireEvent.click(
            screen.getByRole('button', { name: 'Continue anyway' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Next: yearly payments' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(
            screen.getByText('This is how we have estimated your month'),
        ).toBeInTheDocument();
    });

    it('is skipped when the review opens on the yearly payments', () => {
        renderDialog({ review: withNotice, initialStep: 2 });

        expect(
            screen.getByText('What do you pay once or twice a year?'),
        ).toBeInTheDocument();
    });

    it('closes without saving on "Not now"', () => {
        const { onOpenChange } = renderDialog({ review: withNotice });

        fireEvent.click(screen.getByRole('button', { name: 'Not now' }));

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });
});

describe('step 1 of the review', () => {
    it('lists income, then expenses, "Other" last in each', () => {
        renderDialog();

        expect(
            screen.getByText('This is how we have estimated your month'),
        ).toBeInTheDocument();
        expect(rowNames('income')).toEqual(['Salary', 'Other income']);
        expect(rowNames('expenses')).toEqual([
            'Housing',
            'Leisure',
            'Other spending',
        ]);
    });

    it('says where each figure comes from and its likely range', () => {
        renderDialog();

        const rows = document.querySelectorAll<HTMLElement>(
            '[data-test="forecast-review-estimate"]',
        );

        expect(rows[0]).toHaveTextContent('Median of 3 months · Likely ± 250');
        expect(rows[3]).toHaveTextContent('Budget 300 · Likely ± 120');
    });

    it("names each row's account when several are reviewed", () => {
        renderDialog({ review: { ...review, accountId: null } });

        expect(
            document.querySelector('[data-test="forecast-review-estimate"]'),
        ).toHaveTextContent('Median of 3 months · Checking · Likely ± 250');
    });

    it('shows the usual transfers apart', () => {
        renderDialog();

        const transfers = document.querySelector(
            '[data-test="forecast-review-section-transfers"]',
        ) as HTMLElement;
        expect(transfers).toHaveTextContent('Checking → Savings');
        expect(transfers).toHaveTextContent('50000');
    });

    it('adds up income, expenses and the net per month', () => {
        renderDialog();

        const totals = document.querySelector(
            '[data-test="forecast-review-totals"]',
        ) as HTMLElement;
        expect(totals).toHaveTextContent('Income260000');
        expect(totals).toHaveTextContent('Expenses-127000');
        expect(totals).toHaveTextContent('Net per month133000');
    });

    it('names the budgets it could not use', () => {
        renderDialog({
            review: {
                ...review,
                estimates: {
                    ...review.estimates!,
                    unusableBudgets: ['Holidays'],
                },
            },
        });

        expect(screen.getByText(/We cannot use Holidays/)).toBeInTheDocument();
    });

    it('says when there is nothing to estimate yet', () => {
        renderDialog({ review: { ...review, estimates: null } });

        expect(
            screen.getByText(/There is not enough history yet/),
        ).toBeInTheDocument();
    });

    it('changes a figure in place, from next month on', () => {
        renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Change Salary' }));
        fireEvent.change(screen.getByLabelText('New figure for Salary'), {
            target: { value: '260000' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(router.post).toHaveBeenCalledTimes(1);
        const [url, data, options] = router.post.mock.calls[0];
        expect(url).toBe('/forecast/estimates');
        expect(data).toEqual({
            account_id: 'checking',
            category_id: 'salary',
            kind: 'income',
            month: '2026-12',
            amount: 260000,
            scope: 'from_here',
        });
        expect(options.only).toEqual(['review', 'forecast', 'projectionInput']);
    });

    it('changes "Other spending" on its side', () => {
        renderDialog();

        fireEvent.click(
            screen.getByRole('button', { name: 'Change Other spending' }),
        );
        fireEvent.change(
            screen.getByLabelText('New figure for Other spending'),
            { target: { value: '15000' } },
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(router.post.mock.calls[0][1]).toMatchObject({
            category_id: null,
            kind: 'expense',
            amount: 15000,
        });
    });

    it('closes the editor once saved, and shows why when it is not', () => {
        router.post.mockImplementationOnce(
            (
                _url: string,
                _data: unknown,
                options: {
                    onError: (errors: Record<string, string>) => void;
                    onFinish: () => void;
                },
            ) => {
                options.onError({
                    month: 'This month can no longer be changed.',
                });
                options.onFinish();
            },
        );
        renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Change Housing' }));
        fireEvent.change(screen.getByLabelText('New figure for Housing'), {
            target: { value: '90000' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(screen.getByRole('alert')).toHaveTextContent(
            'This month can no longer be changed.',
        );

        router.post.mockImplementationOnce(
            (
                _url: string,
                _data: unknown,
                options: { onSuccess: () => void },
            ) => options.onSuccess(),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(
            screen.queryByLabelText('New figure for Housing'),
        ).not.toBeInTheDocument();
    });

    it('sends nothing when the figure did not change', () => {
        renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Change Housing' }));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(router.post).not.toHaveBeenCalled();
        expect(
            screen.queryByLabelText('New figure for Housing'),
        ).not.toBeInTheDocument();
    });

    it('closes without saving on "Not now"', () => {
        const { onOpenChange } = renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Not now' }));

        expect(onOpenChange).toHaveBeenCalledWith(false);
        expect(router.post).not.toHaveBeenCalled();
    });

    it('asks for the review while it is not loaded', () => {
        renderDialog({ review: undefined });

        expect(router.reload).toHaveBeenCalledWith({ only: ['review'] });
    });
});

describe('step 2 of the review', () => {
    function goToStep2() {
        fireEvent.click(
            screen.getByRole('button', { name: 'Next: yearly payments' }),
        );
    }

    it('shows the yearly charges seen, with what they stand on', () => {
        renderDialog();
        goToStep2();

        expect(
            screen.getByText('What do you pay once or twice a year?'),
        ).toBeInTheDocument();

        const row = document.querySelector(
            '[data-test="forecast-review-annual-suggestion"]',
        ) as HTMLElement;
        expect(row).toHaveTextContent(
            '€612 in April 2025 and €640 in April 2026 · Checking',
        );

        fireEvent.click(
            within(row).getByRole('button', { name: 'Add yearly payment' }),
        );
        expect(within(row).getByText('Added')).toBeInTheDocument();
        expect(screen.getByText('Added (1)')).toBeInTheDocument();
    });

    it('adds a row per shortcut and takes it away again', () => {
        renderDialog();
        goToStep2();

        const chip = screen.getByRole('button', { name: 'Property tax (IBI)' });
        fireEvent.click(chip);

        expect(chip).toHaveAttribute('aria-pressed', 'true');
        expect(
            document.querySelectorAll(
                '[data-test="forecast-review-manual-row"]',
            ),
        ).toHaveLength(1);

        fireEvent.click(
            screen.getByRole('button', { name: 'Remove Property tax (IBI)' }),
        );
        expect(
            document.querySelectorAll(
                '[data-test="forecast-review-manual-row"]',
            ),
        ).toHaveLength(0);
    });

    it('sends the yearly suggestions added and the payments typed in', () => {
        renderDialog();
        goToStep2();

        fireEvent.click(
            screen.getByRole('button', { name: 'Add yearly payment' }),
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Property tax (IBI)' }),
        );
        fireEvent.change(
            screen.getByLabelText('Amount of Property tax (IBI)'),
            { target: { value: '48000' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Extra pay (income)' }),
        );
        fireEvent.change(
            screen.getByLabelText('Amount of Extra pay (income)'),
            { target: { value: '150000' } },
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'See my forecast' }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(postedAnswers()).toEqual({
            annual: ['annual|checking|car insurance'],
            dismissed: [],
            manual: [
                {
                    name: 'Property tax (IBI)',
                    amount: -48000,
                    month: 4,
                    accountId: 'checking',
                    frequency: 'yearly',
                },
                {
                    name: 'Extra pay (income)',
                    amount: 150000,
                    month: 6,
                    accountId: 'checking',
                    frequency: 'yearly',
                },
            ],
        });
    });

    it('dismisses the yearly suggestions not added', () => {
        renderDialog();
        goToStep2();

        fireEvent.click(screen.getByRole('button', { name: 'I have no more' }));

        expect(postedAnswers()).toEqual({
            annual: [],
            dismissed: ['annual|checking|car insurance'],
            manual: [],
        });
    });

    it('goes back to step 1', () => {
        renderDialog();
        goToStep2();

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(
            screen.getByText('This is how we have estimated your month'),
        ).toBeInTheDocument();
    });

    it('does not send a payment added without an amount', () => {
        renderDialog();
        goToStep2();

        fireEvent.click(screen.getByRole('button', { name: 'Vehicle tax' }));
        fireEvent.click(
            screen.getByRole('button', { name: 'See my forecast' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Add the amount of Vehicle tax, or remove it.',
        );
    });

    it('tells a user without Pro where the later payments show', () => {
        renderDialog({ isPro: false });
        goToStep2();

        expect(
            document.querySelector('[data-test="forecast-review-free-note"]'),
        ).not.toBeNull();
    });

    it('closes once the answers are stored', () => {
        router.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onSuccess: () => void },
            ) => options.onSuccess(),
        );
        const { onSaved, onOpenChange } = renderDialog();
        goToStep2();

        fireEvent.click(
            screen.getByRole('button', { name: 'See my forecast' }),
        );

        expect(onSaved).toHaveBeenCalled();
        expect(onOpenChange).not.toHaveBeenCalled();
    });

    it('shows why the answers could not be saved', () => {
        router.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onError: (errors: Record<string, string>) => void },
            ) =>
                options.onError({
                    annual: 'Some suggestions changed since you opened the review. Open it again to see them.',
                }),
        );
        renderDialog();
        goToStep2();

        fireEvent.click(
            screen.getByRole('button', { name: 'See my forecast' }),
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'Some suggestions changed since you opened the review.',
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Load them again' }),
        );
        expect(router.reload).toHaveBeenCalledWith({ only: ['review'] });
    });
});
