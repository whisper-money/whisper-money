import { accountMonths, withLine } from '@/lib/forecast/edits';
import { type ProjectionInput } from '@/lib/forecast/types';
import {
    type ForecastAllData,
    type ForecastData,
    type ForecastReviewPrompt,
} from '@/types/forecast';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { type ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    allForecast,
    allTotal,
    forecast,
    home,
    line,
    MONTHS,
    projected,
} from './forecast.fixture';
import ForecastPage from './index';

const { visit, reload, post, viewport } = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
    viewport: { isMobile: false },
}));

vi.mock('@inertiajs/react', async () => ({
    Head: () => null,
    Link: (await import('@/lib/inertia-link.fixture')).FixtureLink,
    router: { visit, reload, post },
    usePage: () => ({
        props: {
            pricing: { defaultPlan: 'monthly' },
            categories: [
                {
                    id: 'home',
                    name: 'Home',
                    icon: 'House',
                    color: 'blue',
                    type: 'expense',
                    cashflow_direction: 'out',
                    parent_id: null,
                },
            ],
        },
    }),
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => viewport.isMobile,
}));

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/subscription/upgrade-dialog', () => ({
    UpgradeDialog: ({ open }: { open: boolean }) =>
        open ? <div data-test="upgrade-dialog" /> : null,
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-format-amount', () => ({
    useFormatAmount: () => ({
        formatAmount: (amount: number) => `€${amount / 100}`,
        formatAmountNumber: (amount: number) => String(amount / 100),
    }),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

function renderPage(
    data: ForecastData | ForecastAllData,
    projectionInput: ProjectionInput | null = null,
    reviewPrompt: ForecastReviewPrompt = { hasLines: true },
    newSuggestionCount: number | undefined = 0,
) {
    return render(
        <ForecastPage
            forecast={data}
            projectionInput={projectionInput}
            reviewPrompt={reviewPrompt}
            newSuggestionCount={newSuggestionCount}
        />,
    );
}

function selectedColumn(): string | null {
    return (
        document
            .querySelector('[data-test^="forecast-column-"][data-selected]')
            ?.getAttribute('data-test')
            ?.replace('forecast-column-', '') ?? null
    );
}

describe('the forecast page with no account in it', () => {
    it('lists the excluded accounts so one can be switched on', () => {
        visit.mockClear();
        render(
            <ForecastPage
                forecast={{
                    scope: 'account',
                    today: '2026-11-26',
                    horizon: 6,
                    isPro: true,
                    account: null,
                    accounts: [],
                    accountChoices: [
                        {
                            id: 'house',
                            name: 'House',
                            type: 'real_estate',
                            included: false,
                            projectionOnly: true,
                        },
                    ],
                }}
                projectionInput={null}
                reviewPrompt={{ hasLines: false }}
            />,
        );

        const house = document.querySelector(
            '[data-test="forecast-account-choice-house"]',
        ) as HTMLElement;
        expect(house).toHaveTextContent('Not in the forecast');

        fireEvent.click(within(house).getByRole('checkbox'));
        expect(visit.mock.calls.at(-1)?.[0]).toBe('/forecast/accounts/house');
    });
});

describe('the forecast page', () => {
    it('opens the group that explains the problem and folds the rest on demand', () => {
        renderPage(forecast());

        expect(
            screen.getByText('Car insurance', { selector: 'span' }),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Home/ }));
        expect(screen.queryByText('Estimated rest')).not.toBeInTheDocument();

        fireEvent.click(
            document.querySelector('[data-test="forecast-toggle-all"]')!,
        );
        expect(screen.getByText('Estimated rest')).toBeInTheDocument();
        expect(screen.getByText('Collapse all')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Collapse all'));
        expect(screen.queryByText('Estimated rest')).not.toBeInTheDocument();
        expect(screen.getByText('Expand all')).toBeInTheDocument();
    });

    it('selects the problem month first and follows the month picked on the chart', () => {
        renderPage(forecast());

        expect(selectedColumn()).toBe('2027-04');

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-chart-month-2027-01"]',
            )!,
        );
        expect(selectedColumn()).toBe('2027-01');

        const alert = document.querySelector<HTMLElement>(
            '[data-test="forecast-alert-strong"]',
        )!;
        fireEvent.click(
            within(alert).getByRole('button', {
                name: 'See April in the plan',
            }),
        );
        expect(selectedColumn()).toBe('2027-04');
    });

    it('cannot pick a past month', () => {
        renderPage(forecast());

        const past = document.querySelector<HTMLButtonElement>(
            '[data-test="forecast-chart-month-2026-09"]',
        )!;

        expect(past).toBeDisabled();
    });

    it('shows the current month against the plan', () => {
        renderPage(forecast());

        // Home is already open: its yearly insurance explains April.
        expect(screen.getByText('✓ as planned')).toBeInTheDocument();
        expect(screen.getByText('of 60 · 40 left')).toBeInTheDocument();
    });

    it('tells a line still to come from one that is overdue', () => {
        const pending = (id: string, date: string) =>
            line({
                id,
                name: id,
                current: { planned: -100, real: null, date, status: 'pending' },
            });
        renderPage(
            forecast({
                plan: {
                    income: [],
                    expenses: [
                        {
                            ...home,
                            items: [
                                pending('Gym', '2026-11-02'),
                                pending('Phone', '2026-11-28'),
                            ],
                        },
                    ],
                    transfers: [],
                },
                alert: {
                    level: 'ok',
                    month: null,
                    min: null,
                    minDate: null,
                    softMonths: [],
                    causes: [],
                    transfer: null,
                },
            }),
        );
        fireEvent.click(
            document.querySelector('[data-test="forecast-toggle-all"]')!,
        );

        expect(screen.getByText('expected on the 2')).toBeInTheDocument();
        expect(screen.getByText('arrives on the 28')).toBeInTheDocument();
    });

    it('marks the account with a problem and says it in a strong alert', () => {
        renderPage(forecast());

        const tabs = screen.getByRole('navigation', { name: 'Account' });
        expect(
            within(tabs).getByRole('img', { name: 'has a warning' }),
        ).toBeInTheDocument();
        expect(
            document.querySelector('[data-test="forecast-alert-strong"]'),
        ).toHaveTextContent('You will drop to €-500 around 10 April');
        expect(
            document.querySelector('[data-test="forecast-free-banner"]'),
        ).toBeNull();
    });

    it('says the account holds up when nothing is wrong', () => {
        renderPage(
            forecast({
                alert: {
                    level: 'ok',
                    month: null,
                    min: null,
                    minDate: null,
                    softMonths: [],
                    causes: [],
                    transfer: null,
                },
            }),
        );

        expect(
            document.querySelector('[data-test="forecast-alert-ok"]'),
        ).toHaveTextContent('Your Checking holds up for the next 6 months');
    });
});

describe('the forecast page without Pro', () => {
    const locked = forecast({
        isPro: false,
        lockedAlertCount: 1,
        months: [
            { month: '2026-11', status: 'current' },
            { month: '2026-12', status: 'future' },
            ...MONTHS.slice(2).map((month) => ({
                month,
                status: 'locked' as const,
            })),
        ],
        projection: MONTHS.slice(0, 2).map((month) => projected(month, 110000)),
        alert: {
            level: 'ok',
            month: null,
            min: null,
            minDate: null,
            softMonths: [],
            causes: [],
            transfer: null,
        },
        plan: {
            income: [],
            expenses: [
                {
                    ...home,
                    items: [
                        line({
                            amounts: { '2026-11': -190000, '2026-12': -190000 },
                        }),
                    ],
                    estimate: {
                        ...home.estimate!,
                        amounts: { '2026-12': -6000 },
                    },
                },
            ],
            transfers: [],
        },
    });

    it('blurs the locked months, tags them PRO and offers the upgrade', () => {
        renderPage(locked);
        fireEvent.click(
            document.querySelector('[data-test="forecast-toggle-all"]')!,
        );

        const columns = MONTHS.slice(2).map((month) =>
            document.querySelector(`[data-test="forecast-column-${month}"]`),
        );
        columns.forEach((column) => expect(column).toHaveTextContent('PRO'));
        expect(
            document.querySelectorAll('[data-test="forecast-locked-cell"]')
                .length,
        ).toBeGreaterThan(0);
        expect(screen.getAllByText('Pro').length).toBe(4);
        expect(
            document.querySelector('[data-test="forecast-alert-ok"]'),
        ).toBeNull();

        const banner = document.querySelector(
            '[data-test="forecast-free-banner"]',
        )!;
        expect(banner).toHaveTextContent('You have 1 warning further ahead');

        fireEvent.click(
            within(banner as HTMLElement).getByRole('button', {
                name: 'Unlock 12 months with Pro',
            }),
        );
        expect(
            document.querySelector('[data-test="upgrade-dialog"]'),
        ).not.toBeNull();
    });

    it('does not let a locked month be picked', () => {
        renderPage(locked);

        expect(
            document.querySelector(
                '[data-test="forecast-chart-month-2027-03"]',
            ),
        ).toBeDisabled();
    });
});

describe('editing the forecast', () => {
    /** The engine's input for the fixture: rent, salary and a yearly insurance on checking. */
    function editingInput(): ProjectionInput {
        const base: ProjectionInput = {
            accounts: [
                { id: 'checking', openingBalance: 100000 },
                { id: 'savings', openingBalance: 900000 },
            ],
            occurrences: [],
            estimates: [],
            startDate: '2026-11-26',
            horizonMonths: 7,
        };
        const rent = forecast().plan.expenses[0].items[0].definition;

        return withLine(
            withLine(withLine(base, 'rent', [rent]), 'salary', [
                {
                    ...rent,
                    id: 'salary',
                    anchorDate: '2026-01-01',
                    versions: [{ effectiveFrom: '2026-01-01', amount: 300000 }],
                },
            ]),
            'insurance',
            [
                {
                    ...rent,
                    id: 'insurance',
                    frequency: 'yearly',
                    anchorDate: '2027-04-03',
                    versions: [
                        { effectiveFrom: '2027-04-03', amount: -705000 },
                    ],
                },
            ],
        );
    }

    function editable(overrides: Partial<ForecastData> = {}): ForecastData {
        const base = forecast(overrides);
        const [rent, insurance] = base.plan.expenses[0].items;

        return {
            ...base,
            projection: accountMonths(editingInput(), 'checking'),
            plan: {
                ...base.plan,
                expenses: [
                    {
                        ...base.plan.expenses[0],
                        items: [
                            {
                                ...rent,
                                occurrenceDates: Object.fromEntries(
                                    [...MONTHS, '2027-05'].map((month) => [
                                        month,
                                        `${month}-05`,
                                    ]),
                                ),
                                drift: { amount: -201000, from: '2026-12-05' },
                            },
                            {
                                ...insurance,
                                definition: {
                                    ...insurance.definition,
                                    id: 'insurance',
                                    frequency: 'yearly',
                                    anchorDate: '2027-04-03',
                                },
                                occurrenceDates: { '2027-04': '2027-04-03' },
                            },
                        ],
                    },
                ],
            },
        };
    }

    function lastVisit() {
        return visit.mock.calls.at(-1) as [
            string,
            {
                method: string;
                data: Record<string, unknown>;
                only: string[];
                optimistic: (props: unknown) => {
                    forecast: ForecastData;
                    projectionInput: ProjectionInput;
                };
            },
        ];
    }

    function sheet(test: string): HTMLElement {
        return document.querySelector(`[data-test="${test}"]`) as HTMLElement;
    }

    it('opens the line of a month, shows what the change does and saves it optimistically', () => {
        visit.mockClear();
        const data = editable();
        renderPage(data, editingInput());

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-cell-line:rent-2027-03"]',
            )!,
        );

        const edit = sheet('forecast-line-sheet');
        expect(edit).toHaveTextContent('Rent · March 2027');
        expect(edit).toHaveTextContent('Before: €1900');
        expect(edit).toHaveTextContent('March 2027 onwards');

        fireEvent.change(within(edit).getByLabelText('Amount'), {
            target: { value: '2000' },
        });
        expect(within(edit).getByRole('status')).toHaveTextContent(
            'With this change, the minimum of April 2027 drops from €-550 to €-750.',
        );

        fireEvent.click(within(edit).getByRole('button', { name: 'Save' }));

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items/rent');
        expect(options.method).toBe('patch');
        expect(options.only).toEqual(['forecast', 'projectionInput']);
        expect(options.data).toMatchObject({
            scope: 'from_here',
            occurrence_date: '2027-03-05',
            amount: 200000,
        });

        const optimistic = options.optimistic({
            forecast: data,
            projectionInput: editingInput(),
        });
        expect(optimistic.forecast.alert).toMatchObject({
            level: 'strong',
            month: '2027-04',
            min: -75000,
        });
        expect(
            optimistic.forecast.plan.expenses[0].items[0].amounts,
        ).toMatchObject({ '2027-02': -190000, '2027-03': -200000 });
    });

    it('opens a line tapped on the phone as a full-height bottom sheet', () => {
        viewport.isMobile = true;

        try {
            renderPage(editable(), editingInput());

            fireEvent.click(
                document.querySelector(
                    '[data-test="forecast-entry-line:insurance"]',
                )!,
            );

            const edit = sheet('forecast-line-sheet');
            expect(edit).toHaveTextContent('Car insurance · April 2027');
            expect(edit.className).toContain('h-[calc(100dvh');
            expect(edit.className).toContain('bottom-0');
        } finally {
            viewport.isMobile = false;
        }
    });

    it('stops forecasting a line', () => {
        visit.mockClear();
        renderPage(editable(), editingInput());

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-cell-line:insurance-2027-04"]',
            )!,
        );
        fireEvent.click(
            within(sheet('forecast-line-sheet')).getByRole('button', {
                name: 'Stop forecasting it',
            }),
        );
        expect(visit).not.toHaveBeenCalled();
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Stop forecasting it',
            }),
        );

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items/insurance');
        expect(options.method).toBe('delete');
        expect(
            options.optimistic({
                forecast: editable(),
                projectionInput: editingInput(),
            }).forecast.alert.level,
        ).toBe('ok');
    });

    it('opens the suggested transfer from the warning, prefilled, with before and after', () => {
        visit.mockClear();
        const transfer = {
            fromAccountId: 'savings',
            amount: 60000,
            date: '2027-04-01',
            month: '2027-04',
        };
        const data = editable();
        renderPage(
            { ...data, alert: { ...data.alert, transfer } },
            editingInput(),
        );

        expect(
            document.querySelector(
                '[data-test="forecast-cell-suggestion:transfer-2027-04"]',
            ),
        ).toHaveTextContent('+600?');

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Plan a transfer of €600 from Savings',
            }),
        );

        const add = sheet('forecast-add-sheet');
        expect(
            within(add).getByRole('radio', { name: 'Transfer' }),
        ).toHaveAttribute('aria-checked', 'true');
        expect(sheet('forecast-before-after')).toHaveTextContent(
            'This is how April looks',
        );
        expect(sheet('forecast-before-after')).toHaveTextContent(
            'Checking · min.€-550→€50',
        );
        expect(sheet('forecast-before-after')).toHaveTextContent(
            'Total of your accounts',
        );

        fireEvent.click(
            within(add).getByRole('button', { name: 'Add transfer' }),
        );

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items');
        expect(options.method).toBe('post');
        expect(options.data).toMatchObject({
            type: 'transfer',
            account_id: 'savings',
            destination_account_id: 'checking',
            destination_external: false,
            amount: 60000,
            date: '2027-04-01',
            frequency: 'once',
        });
    });

    it('only opens the months that can still change', () => {
        renderPage(editable(), editingInput());

        expect(
            document.querySelector(
                '[data-test="forecast-cell-line:rent-2026-11"]',
            ),
        ).toBeNull();
        expect(
            document.querySelector(
                '[data-test="forecast-cell-line:insurance-2027-03"]',
            ),
        ).toBeNull();
    });

    it('opens nothing without the projection input', () => {
        renderPage(editable());

        expect(
            document.querySelector('[data-test^="forecast-cell-"]'),
        ).toBeNull();
        expect(
            document.querySelector('[data-test="forecast-add"]'),
        ).toBeDisabled();
    });

    it('adds a line in a category, adjusts an estimate and changes the wanted minimum', () => {
        visit.mockClear();
        renderPage(editable(), editingInput());

        fireEvent.click(
            screen.getByRole('button', {
                name: '+ Add something concrete in Home',
            }),
        );
        expect(
            within(sheet('forecast-add-sheet')).getByRole('radio', {
                name: 'Expense',
            }),
        ).toHaveAttribute('aria-checked', 'true');
        fireEvent.click(
            within(sheet('forecast-add-sheet')).getByRole('button', {
                name: 'Cancel',
            }),
        );

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-cell-estimate:home-2027-01"]',
            )!,
        );
        const estimate = sheet('forecast-estimate-sheet');
        expect(estimate).toHaveTextContent(
            'Estimated rest of Home · January 2027',
        );
        fireEvent.change(
            within(estimate).getByLabelText('What you expect to spend'),
            {
                target: { value: '100' },
            },
        );
        fireEvent.click(within(estimate).getByRole('button', { name: 'Save' }));
        expect(lastVisit()[1].data).toEqual({
            account_id: 'checking',
            category_id: 'home',
            month: '2027-01',
            amount: 10000,
            scope: 'from_here',
        });

        fireEvent.click(
            document.querySelector('[data-test="forecast-change-minimum"]')!,
        );
        expect(sheet('forecast-account-sheet')).toHaveTextContent(
            'Wanted minimum',
        );
    });

    it('lists every account, an excluded house can be switched on, and a projection-only one never asks for a minimum', () => {
        visit.mockClear();
        const data = editable({
            accountChoices: [
                ...forecast().accountChoices,
                {
                    id: 'broker',
                    name: 'Broker',
                    type: 'investment',
                    included: true,
                    projectionOnly: true,
                },
                {
                    id: 'house',
                    name: 'House',
                    type: 'real_estate',
                    included: false,
                    projectionOnly: true,
                },
            ],
        });
        renderPage(data, editingInput());

        fireEvent.click(
            document.querySelector('[data-test="forecast-choose-accounts"]')!,
        );

        const accounts = sheet('forecast-accounts-sheet');
        expect(
            accounts.querySelector(
                '[data-test="forecast-account-choice-broker"]',
            ),
        ).toHaveTextContent(
            'Projection only: counts toward the total, never warns',
        );

        const house = accounts.querySelector(
            '[data-test="forecast-account-choice-house"]',
        ) as HTMLElement;
        expect(house).toHaveTextContent('Not in the forecast');

        fireEvent.click(within(house).getByRole('checkbox'));
        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/accounts/house');
        expect(options.method).toBe('patch');
        expect(options.data).toEqual({ included: true });
    });

    it('says a projection-only account never warns instead of asking for its minimum', () => {
        const base = forecast();
        renderPage(
            editable({
                account: {
                    ...base.account,
                    type: 'investment',
                    projectionOnly: true,
                    alertThreshold: null,
                },
            }),
            editingInput(),
        );

        expect(
            document.querySelector('[data-test="forecast-kpis"]') ??
                document.body,
        ).toHaveTextContent('Projection only, never warns');

        fireEvent.click(
            document.querySelector('[data-test="forecast-change-minimum"]')!,
        );
        const settings = sheet('forecast-account-sheet');
        expect(settings).not.toHaveTextContent('Wanted minimum');
        expect(
            settings.querySelector(
                '[data-test="forecast-account-never-warns"]',
            ),
        ).not.toBeNull();

        fireEvent.click(
            settings.querySelector(
                '[data-test="forecast-account-choose-accounts"]',
            )!,
        );
        expect(sheet('forecast-accounts-sheet')).not.toBeNull();
    });

    it("accepts the update a line's last payments suggest", () => {
        visit.mockClear();
        renderPage(editable(), editingInput());

        fireEvent.click(
            document.querySelector('[data-test="forecast-drift-rent"]')!,
        );

        const [url, options] = lastVisit();
        expect(url).toBe('/forecast/items/rent/drift');
        expect(options.method).toBe('post');
    });
});

describe('the review on the forecast page', () => {
    beforeEach(() => {
        reload.mockClear();
        window.localStorage.clear();
    });

    it('opens on its own for a user with no line yet, and asks for it', () => {
        renderPage(forecast(), null, { hasLines: false }, 3);

        expect(
            document.querySelector('[data-test="forecast-review"]'),
        ).not.toBeNull();
        expect(reload).toHaveBeenCalledWith({ only: ['review'] });
        expect(
            document.querySelector('[data-test="forecast-no-lines"]'),
        ).toHaveTextContent('Review what we found (3)');
    });

    it('does not open on its own again once skipped', () => {
        window.localStorage.setItem('forecast-review-skipped:guest', '1');

        renderPage(forecast(), null, { hasLines: false }, 3);

        expect(
            document.querySelector('[data-test="forecast-review"]'),
        ).toBeNull();

        fireEvent.click(
            document.querySelector('[data-test="forecast-start-review"]')!,
        );
        expect(
            document.querySelector('[data-test="forecast-review"]'),
        ).not.toBeNull();
    });

    it('links the new suggestions from the plan once there are lines', () => {
        renderPage(forecast(), null, { hasLines: true }, 2);

        expect(
            document.querySelector('[data-test="forecast-review"]'),
        ).toBeNull();
        expect(
            document.querySelector('[data-test="forecast-no-lines"]'),
        ).toBeNull();

        const link = document.querySelector(
            '[data-test="forecast-review-link"]',
        )!;
        expect(link).toHaveTextContent('Recurring detected (2 new)');

        fireEvent.click(link);
        expect(
            document.querySelector('[data-test="forecast-review"]'),
        ).not.toBeNull();
    });

    it('remembers the review was skipped when closed without saving', () => {
        renderPage(forecast(), null, { hasLines: false }, 3);

        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });

        expect(
            window.localStorage.getItem('forecast-review-skipped:guest'),
        ).toBe('1');
    });

    it('starts the forecast over after asking, and opens the review again', async () => {
        window.localStorage.setItem('forecast-review-skipped:guest', '1');
        post.mockClear();
        renderPage(forecast(), null, { hasLines: true }, 0);

        fireEvent.pointerDown(screen.getByLabelText('More options'), {
            button: 0,
            ctrlKey: false,
        });
        fireEvent.click(
            within(await screen.findByRole('menu')).getByText('Start over'),
        );

        expect(
            screen.getByRole('alertdialog', {
                name: 'Start your forecast over?',
            }),
        ).toHaveTextContent('Your edits, yearly payments and alerts');
        expect(post).not.toHaveBeenCalled();

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-start-over-confirm"]',
            )!,
        );

        expect(post).toHaveBeenCalledWith(
            '/forecast/start-over',
            {},
            expect.anything(),
        );

        act(() => post.mock.calls[0][2].onSuccess());

        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(
            window.localStorage.getItem('forecast-review-skipped:guest'),
        ).toBeNull();
        expect(
            document.querySelector('[data-test="forecast-review"]'),
        ).not.toBeNull();
    });

    it('has no link when nothing new was found', () => {
        renderPage(forecast(), null, { hasLines: true }, 0);

        expect(
            document.querySelector('[data-test="forecast-review-link"]'),
        ).toBeNull();
    });
});

describe('the "All accounts" view', () => {
    const total = allTotal;
    const all = allForecast;

    function row(id: string): HTMLElement {
        return document.querySelector(
            `[data-test="forecast-row-${id}"]`,
        ) as HTMLElement;
    }

    it('selects the "All accounts" tab and says the money is in the wrong account', () => {
        renderPage(all());

        expect(
            screen.getByRole('link', { name: 'All accounts' }).className,
        ).toContain('bg-primary');

        const alert = document.querySelector(
            '[data-test="forecast-all-alert-split"]',
        )!;
        expect(alert).toHaveTextContent(
            'Together you are doing fine, but the money is not where you need it',
        );
        expect(alert).toHaveTextContent(
            'Your total holds in April, but Checking drops to €-500. You have enough in Savings to cover it with a transfer.',
        );
        expect(
            within(alert as HTMLElement).getByRole('link', {
                name: 'See Checking',
            }),
        ).toBeInTheDocument();
    });

    it('says the total itself does not hold when no account can lend', () => {
        renderPage(
            all({
                alert: { ...all().alert, level: 'short', transfer: null },
            }),
        );

        expect(
            document.querySelector('[data-test="forecast-all-alert-short"]'),
        ).toHaveTextContent('Together your accounts do not cover April');
        expect(
            document.querySelector('[data-test="forecast-all-plan-transfer"]'),
        ).toBeNull();
    });

    it('links the new suggestions to the review over the plan', () => {
        renderPage(all(), null, { hasLines: true }, 2);

        const link = document.querySelector(
            '[data-test="forecast-review-link"]',
        )!;
        expect(link).toHaveTextContent('Recurring detected (2 new)');

        fireEvent.click(link);
        expect(
            document.querySelector('[data-test="forecast-review"]'),
        ).not.toBeNull();
    });

    it('shows the four figures of the total', () => {
        renderPage(all());

        const kpis = document.querySelector('[data-test="forecast-kpis"]')!;
        expect(kpis).toHaveTextContent('Balance today · 2 accounts');
        expect(kpis).toHaveTextContent('Where the rise comes from+€5000');
        expect(kpis).toHaveTextContent('saved · +€450 from growth');
        expect(kpis).toHaveTextContent('Moved between your accounts');
        expect(kpis).toHaveTextContent(
            'It neither adds to nor takes from your total',
        );
    });

    it('marks the months an account runs short on the chart and the plan', () => {
        renderPage(all());

        expect(
            document.querySelector(
                '[data-test="forecast-chart-month-2027-04"]',
            ),
        ).toHaveTextContent('● 1 account');
        expect(
            document.querySelector('[data-test="forecast-column-2027-04"]'),
        ).toHaveTextContent('⚠ 1 account');
        expect(
            document.querySelector('[data-test="forecast-column-2027-03"]'),
        ).not.toHaveTextContent('⚠');
    });

    it('sums the categories across accounts, lines saying their account', () => {
        renderPage(all());

        const group = row('group:expenses:home');
        expect(group).toHaveTextContent('2 concrete + estimated');
        expect(group).toHaveTextContent('≈ -2060');
        expect(group).toHaveTextContent('-1920');

        fireEvent.click(within(group).getByRole('button'));

        expect(row('line:rent')).toHaveTextContent(
            'Checking · Detected · day 5',
        );
        expect(row('line:cleaning')).toHaveTextContent('Savings · Detected');
        expect(row('estimate:home:savings')).toHaveTextContent(
            'Savings · Median of 3 months',
        );
    });

    it('lists the growth, the transfers neutral in the total and each account’s close', () => {
        renderPage(all());

        expect(row('growth')).toHaveTextContent('Savings · +12% a year');
        expect(row('growth')).toHaveTextContent('+90');
        expect(row('line:to-savings')).toHaveTextContent('Checking → Savings');
        expect(row('line:to-savings')).toHaveTextContent('500');
        expect(row('effect')).toHaveTextContent('Effect on the total');
        expect(row('account:checking')).toHaveTextContent(
            'Wanted minimum €300',
        );
        expect(row('account:checking')).toHaveTextContent('min. -500');
        expect(row('account:savings')).not.toHaveTextContent('min.');
        expect(row('total')).toHaveTextContent(
            String((total[1].close as number) / 100),
        );
    });

    it("edits a line on its own account and waits for the server's answer", () => {
        visit.mockClear();
        const input: ProjectionInput = {
            accounts: [
                { id: 'checking', openingBalance: 100000 },
                { id: 'savings', openingBalance: 900000 },
            ],
            occurrences: [],
            estimates: [],
            startDate: '2026-11-26',
            horizonMonths: 7,
        };
        renderPage(all(), input);

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-cell-line:to-savings-2026-12"]',
            )!,
        );

        const sheet = document.querySelector(
            '[data-test="forecast-line-sheet"]',
        )!;
        expect(sheet).toHaveTextContent('Transfer · Checking');
    });

    it('opens the suggested transfer from the warning', () => {
        renderPage(all(), {
            accounts: [
                { id: 'checking', openingBalance: 100000 },
                { id: 'savings', openingBalance: 900000 },
            ],
            occurrences: [],
            estimates: [],
            startDate: '2026-11-26',
            horizonMonths: 7,
        });

        fireEvent.click(
            document.querySelector('[data-test="forecast-all-plan-transfer"]')!,
        );

        expect(
            document.querySelector('[data-test="forecast-add-sheet"]'),
        ).not.toBeNull();
    });

    it('locks the months the free plan hides', () => {
        const visible = MONTHS.slice(0, 2);
        renderPage(
            all({
                isPro: false,
                lockedAlertCount: 1,
                alert: { ...all().alert, level: 'ok', accountId: null },
                months: MONTHS.map((month, index) => ({
                    month,
                    status:
                        index === 0
                            ? ('current' as const)
                            : index === 1
                              ? ('future' as const)
                              : ('locked' as const),
                })),
                projection: total.slice(0, 2),
                accountForecasts: all().accountForecasts.map((account) => ({
                    ...account,
                    projection: account.projection.filter((month) =>
                        visible.includes(month.month),
                    ),
                })),
            }),
        );

        expect(
            document.querySelector('[data-test="forecast-free-banner"]'),
        ).toHaveTextContent('You have 1 warning further ahead');
        expect(
            document.querySelector('[data-test="forecast-column-2027-04"]'),
        ).toHaveTextContent('PRO');
        expect(
            document.querySelectorAll('[data-test="forecast-locked-cell"]')
                .length,
        ).toBeGreaterThan(0);
        expect(
            document.querySelector('[data-test="forecast-all-alert-ok"]'),
        ).toBeNull();
    });
});

describe('the forecast page on a phone', () => {
    beforeEach(() => {
        viewport.isMobile = true;
    });

    afterEach(() => {
        viewport.isMobile = false;
    });

    function chip(month: string): HTMLElement {
        return document.querySelector(
            `[data-test="forecast-month-chip-${month}"]`,
        ) as HTMLElement;
    }

    function monthCard(): HTMLElement {
        return document.querySelector(
            '[data-test="forecast-month-card"]',
        ) as HTMLElement;
    }

    function group(id: string): HTMLElement {
        return document.querySelector(
            `[data-test="forecast-month-group-${id}"]`,
        ) as HTMLElement;
    }

    it('shows one month at a time instead of the plan table', () => {
        renderPage(forecast());

        expect(
            document.querySelector('[data-test="forecast-mobile"]'),
        ).toBeInTheDocument();
        expect(
            document.querySelector('[data-test="forecast-plan"]'),
        ).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Add to the forecast' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText(/How you are going to be/),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Balance today')).toBeInTheDocument();
        expect(
            screen.getByText('likely between €2020 and €2180'),
        ).toBeInTheDocument();
    });

    it('picks the problem month first, marks the months that run short and follows the chip or the chart', () => {
        renderPage(forecast());

        expect(chip('2027-04')).toHaveAttribute('aria-pressed', 'true');
        expect(chip('2027-04')).toHaveTextContent('⚠');
        expect(chip('2027-01')).not.toHaveTextContent('⚠');
        expect(within(monthCard()).getByRole('heading')).toHaveTextContent(
            'April 2027',
        );
        expect(monthCard()).toHaveTextContent('worst case €-580');

        fireEvent.click(chip('2027-01'));
        expect(chip('2027-01')).toHaveAttribute('aria-pressed', 'true');
        expect(chip('2027-04')).toHaveAttribute('aria-pressed', 'false');
        expect(within(monthCard()).getByRole('heading')).toHaveTextContent(
            'January 2027',
        );

        fireEvent.click(
            document.querySelector(
                '[data-test="forecast-mobile-bar-2027-03"]',
            )!,
        );
        expect(chip('2027-03')).toHaveAttribute('aria-pressed', 'true');
        expect(
            document.querySelector('[data-test="forecast-mobile-bar-2026-10"]'),
        ).toBeDisabled();
    });

    it('opens a category into its concrete lines, its estimated rest and the add link', () => {
        renderPage(forecast());

        // April holds the yearly insurance that explains the dip: open.
        expect(group('group:expenses:home')).toHaveTextContent(
            '2 concrete this month + estimated',
        );
        expect(group('group:expenses:home')).toHaveTextContent('≈ -8960');
        expect(
            within(group('group:expenses:home')).getByText('Car insurance'),
        ).toBeInTheDocument();

        fireEvent.click(chip('2027-01'));
        const home = group('group:expenses:home');
        const toggle = within(home).getByRole('button', { name: /Home/ });
        expect(toggle).toHaveAttribute('aria-expanded', 'false');
        expect(within(home).queryByText('Rent')).not.toBeInTheDocument();

        fireEvent.click(toggle);
        expect(toggle).toHaveAttribute('aria-expanded', 'true');
        expect(within(home).getByText('Rent')).toBeInTheDocument();
        expect(within(home).getByText('Estimated rest')).toBeInTheDocument();
        expect(within(home).getByText('≈ -60')).toBeInTheDocument();
        expect(
            within(home).getByText('+ Add something concrete in Home'),
        ).toBeInTheDocument();
        expect(within(home).queryByText('Car insurance')).toBeNull();
    });

    it('says in the month in progress what was already paid, and leaves it closed to edits', () => {
        renderPage(forecast({ months: forecast().months }));

        fireEvent.click(chip('2026-11'));
        const home = group('group:expenses:home');
        fireEvent.click(within(home).getByRole('button', { name: /Home/ }));

        const rent = home.querySelector<HTMLElement>(
            '[data-test="forecast-entry-line:rent"]',
        )!;
        expect(rent).toHaveTextContent('paid');
        expect(rent.tagName).toBe('DIV');
        // What is left of the estimate: 60 planned, 20 spent.
        expect(within(home).getByText('≈ -40')).toBeInTheDocument();
    });

    it('reaches the account’s wanted minimum from today’s balance', () => {
        renderPage(forecast());

        expect(
            within(
                document.querySelector<HTMLElement>(
                    '[data-test="forecast-kpis"]',
                )!,
            ).getByText(/No wanted minimum/),
        ).toBeInTheDocument();
    });

    it('lists the income and the transfers of the month as their own rows', () => {
        const salary = line({
            id: 'salary',
            name: 'Salary',
            categoryId: null,
            amounts: { '2027-04': 300000 },
            occurrenceDates: { '2027-04': '2027-04-28' },
        });
        const toSavings = line({
            id: 'to-savings',
            name: 'To savings',
            kind: 'transfer',
            direction: 'out',
            counterpartyName: 'Savings',
            amounts: { '2027-04': -50000 },
        });
        renderPage(
            forecast({
                plan: {
                    income: [
                        {
                            ...home,
                            key: 'work',
                            name: 'Work',
                            items: [salary],
                            estimate: null,
                        },
                    ],
                    expenses: [home],
                    transfers: [toSavings],
                },
            }),
        );

        expect(group('income')).toHaveTextContent('Salary · day 28');
        expect(group('income')).toHaveTextContent('+3000');
        expect(group('transfers')).toHaveTextContent(
            '→ Savings · not spending',
        );
        expect(group('transfers')).toHaveTextContent('-500');
    });

    it('moves to another account or horizon from the selects', () => {
        visit.mockClear();
        renderPage(forecast());

        const account = screen.getByLabelText('Account');
        expect(account).toHaveValue('checking');
        expect(
            within(account).getByRole('option', { name: 'Checking · ⚠' }),
        ).toBeInTheDocument();
        expect(
            within(account).getByRole('option', { name: 'All accounts' }),
        ).toBeInTheDocument();

        fireEvent.change(account, { target: { value: 'savings' } });
        expect(visit.mock.calls.at(-1)?.[0]).toBe(
            '/forecast?account=savings&horizon=6',
        );

        fireEvent.change(screen.getByLabelText('Horizon'), {
            target: { value: '12' },
        });
        expect(visit.mock.calls.at(-1)?.[0]).toBe(
            '/forecast?account=checking&horizon=12',
        );
    });

    it('stacks the strong warning over one full-width button', () => {
        renderPage(forecast());

        const alert = document.querySelector<HTMLElement>(
            '[data-test="forecast-alert-strong"]',
        )!;
        expect(alert.className).toContain('flex-col');

        fireEvent.click(chip('2027-01'));
        fireEvent.click(
            within(alert).getByRole('button', {
                name: 'See April in the plan',
            }),
        );
        expect(chip('2027-04')).toHaveAttribute('aria-pressed', 'true');
    });

    it('locks the months the free plan hides and shows the upsell instead of their figures', () => {
        renderPage(
            forecast({
                isPro: false,
                lockedAlertCount: 1,
                months: [
                    { month: '2026-11', status: 'current' },
                    { month: '2026-12', status: 'future' },
                    ...MONTHS.slice(2).map((month) => ({
                        month,
                        status: 'locked' as const,
                    })),
                ],
                projection: MONTHS.slice(0, 2).map((month) =>
                    projected(month, 110000),
                ),
                alert: {
                    level: 'ok',
                    month: null,
                    min: null,
                    minDate: null,
                    softMonths: [],
                    causes: [],
                    transfer: null,
                },
            }),
        );

        expect(chip('2026-12')).toHaveAttribute('aria-pressed', 'true');
        expect(chip('2026-12')).not.toHaveAttribute('data-locked');
        expect(chip('2027-02')).toHaveAttribute('data-locked', 'true');
        expect(
            within(chip('2027-02')).getByLabelText('Available with Pro'),
        ).toBeInTheDocument();

        fireEvent.click(chip('2027-02'));
        expect(monthCard()).toBeNull();
        const locked = document.querySelector<HTMLElement>(
            '[data-test="forecast-month-locked"]',
        )!;
        expect(locked).toHaveTextContent('February 2027');
        expect(locked).not.toHaveTextContent('Rent');

        fireEvent.click(
            within(locked).getByRole('button', {
                name: 'Unlock 12 months with Pro',
            }),
        );
        expect(
            document.querySelector('[data-test="upgrade-dialog"]'),
        ).toBeInTheDocument();
    });

    it('shows the "All accounts" month with each category summed in the user\'s currency', () => {
        renderPage(allForecast());

        expect(screen.getByLabelText('Account')).toHaveValue('all');

        fireEvent.click(chip('2026-12'));
        const home = group('group:expenses:home');
        expect(home).toHaveTextContent('≈ -2060');

        fireEvent.click(within(home).getByRole('button', { name: /Home/ }));
        expect(within(home).getByText('Cleaning')).toBeInTheDocument();
        expect(within(home).getAllByText('Savings')).toHaveLength(2);
        expect(group('transfers')).toHaveTextContent('← Checking');
    });
});
