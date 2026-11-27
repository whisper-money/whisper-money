import {
    type ForecastAnnualSuggestion,
    type ForecastReview,
    type ForecastSuggestion,
} from '@/types/forecast';
import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ForecastReviewDialog } from './forecast-review-dialog';

const router = vi.hoisted(() => ({ reload: vi.fn(), post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router }));

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

function suggestion(
    overrides: Partial<ForecastSuggestion> & Pick<ForecastSuggestion, 'key'>,
): ForecastSuggestion {
    return {
        kind: 'recurring',
        mergeable: true,
        name: overrides.key,
        accountId: 'checking',
        accountName: 'Checking',
        destinationAccountId: null,
        destinationAccountName: null,
        destinationUnknown: false,
        currencyCode: 'EUR',
        frequency: 'monthly',
        anchorDay: 1,
        nextDate: '2026-12-01',
        amount: -10000,
        amountTolerance: 1000,
        confidence: 'high',
        chargeCount: 12,
        expectedCount: 12,
        ...overrides,
    };
}

const insurance: ForecastAnnualSuggestion = {
    ...suggestion({
        key: 'annual|checking|car insurance',
        kind: 'annual',
        name: 'Car insurance',
        frequency: 'yearly',
        nextDate: '2027-04-10',
        amount: -64000,
        confidence: 'review',
        chargeCount: 2,
        expectedCount: 2,
    }),
    evidence: [
        { date: '2025-04-12', amount: -61200 },
        { date: '2026-04-10', amount: -64000 },
    ],
};

const review: ForecastReview = {
    recurring: [
        suggestion({
            key: 'salary',
            name: 'NOMINA ACME',
            amount: 245000,
            anchorDay: 28,
        }),
        suggestion({ key: 'rent', name: 'ALQUILER', amount: -85000 }),
        suggestion({
            key: 'gym',
            name: 'GYM',
            amount: -3900,
            confidence: 'review',
            chargeCount: 4,
            expectedCount: 6,
        }),
        suggestion({
            key: 'savings',
            kind: 'transfer',
            name: 'TRASPASO',
            amount: -50000,
            destinationAccountId: 'savings',
            destinationAccountName: 'Savings',
        }),
    ],
    annual: [insurance],
    accounts: [
        { id: 'checking', name: 'Checking', currencyCode: 'EUR' },
        { id: 'savings', name: 'Savings', currencyCode: 'EUR' },
    ],
    dailySpend: {
        currencyCode: 'EUR',
        budgets: [{ name: 'Groceries', amount: 38000 }],
        median: { name: 'Transport', amount: 17000 },
        unusableBudgets: ['Holidays'],
    },
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

function rows(): HTMLElement[] {
    return Array.from(
        document.querySelectorAll<HTMLElement>(
            '[data-test="forecast-review-row"]',
        ),
    );
}

function postedAnswers(): Record<string, unknown> {
    return router.post.mock.calls[0][1];
}

beforeEach(() => {
    router.post.mockClear();
    router.reload.mockClear();
});

describe('step 1 of the review', () => {
    it('groups the suggestions and ticks only the sure ones', () => {
        renderDialog();

        expect(
            screen.getByText('We found 4 movements that repeat'),
        ).toBeInTheDocument();

        const income = document.querySelector(
            '[data-test="forecast-review-section-income"]',
        ) as HTMLElement;
        const expenses = document.querySelector(
            '[data-test="forecast-review-section-expenses"]',
        ) as HTMLElement;
        const transfers = document.querySelector(
            '[data-test="forecast-review-section-transfers"]',
        ) as HTMLElement;

        expect(within(income).getByText('Income')).toBeInTheDocument();
        expect(within(income).getByText('NOMINA ACME')).toBeInTheDocument();
        expect(within(expenses).getByText('ALQUILER')).toBeInTheDocument();
        expect(within(expenses).getByText('GYM')).toBeInTheDocument();
        expect(
            within(transfers).getByText('Transfers between your accounts'),
        ).toBeInTheDocument();
        expect(
            within(income).getByText(
                'Monthly · day 28 · Checking · 12 of 12 months',
            ),
        ).toBeInTheDocument();
        expect(
            within(transfers).getByText(
                'Monthly · day 1 · Checking → Savings · 12 of 12 months',
            ),
        ).toBeInTheDocument();

        const gym = rows()[2];
        expect(within(gym).getByText('Review')).toBeInTheDocument();
        expect(within(gym).getByRole('checkbox')).not.toBeChecked();
        expect(within(rows()[0]).getByText('Sure')).toBeInTheDocument();
        expect(within(rows()[0]).getByRole('checkbox')).toBeChecked();

        expect(
            document.querySelector('[data-test="forecast-review-selected"]'),
        ).toHaveTextContent('3 of 4 selected');
    });

    it('explains how the day-to-day spend is estimated', () => {
        renderDialog();

        const box = document.querySelector(
            '[data-test="forecast-review-daily"]',
        ) as HTMLElement;

        expect(box).toHaveTextContent(
            'We will use your budgets: Groceries €380.',
        );
        expect(box).toHaveTextContent('(Transport ≈ €170)');
        expect(box).toHaveTextContent('We cannot use Holidays');
    });

    it('updates the count as rows are ticked', () => {
        renderDialog();

        fireEvent.click(within(rows()[2]).getByRole('checkbox'));

        expect(
            document.querySelector('[data-test="forecast-review-selected"]'),
        ).toHaveTextContent('4 of 4 selected');
    });

    it('renames a row in place', () => {
        renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'ALQUILER' }));
        const input = screen.getByRole('textbox', { name: 'Name' });
        fireEvent.change(input, { target: { value: 'Rent' } });
        fireEvent.keyDown(input, { key: 'Enter' });
        fireEvent.blur(input);

        expect(
            screen.getByRole('button', { name: 'Rent' }),
        ).toBeInTheDocument();
    });

    it('focuses the name with all its text selected when Rename is picked', async () => {
        renderDialog();

        fireEvent.pointerDown(
            screen.getByRole('button', { name: 'More options for ALQUILER' }),
            { button: 0, ctrlKey: false },
        );
        fireEvent.click(screen.getByRole('menuitem', { name: 'Rename' }));

        const input = await screen.findByRole('textbox', { name: 'Name' });

        await waitFor(() => expect(document.activeElement).toBe(input));
        expect(input).toHaveProperty('selectionStart', 0);
        expect(input).toHaveProperty('selectionEnd', 'ALQUILER'.length);
    });

    it('focuses the name with all its text selected when the name is clicked', () => {
        renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'ALQUILER' }));
        const input = screen.getByRole('textbox', { name: 'Name' });

        expect(document.activeElement).toBe(input);
        expect(input).toHaveProperty('selectionStart', 0);
        expect(input).toHaveProperty('selectionEnd', 'ALQUILER'.length);
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
        expect(screen.queryByText('Not now')).toBeNull();
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

    it('sends what was kept, what was left out and what was typed in', () => {
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
            lines: [
                {
                    keys: ['salary'],
                    name: 'NOMINA ACME',
                    destinationAccountId: null,
                },
                {
                    keys: ['rent'],
                    name: 'ALQUILER',
                    destinationAccountId: null,
                },
                {
                    keys: ['savings'],
                    name: 'TRASPASO',
                    destinationAccountId: null,
                },
                {
                    keys: ['annual|checking|car insurance'],
                    name: 'Car insurance',
                    destinationAccountId: null,
                },
            ],
            dismissed: ['gym'],
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

        expect(postedAnswers().dismissed).toEqual([
            'gym',
            'annual|checking|car insurance',
        ]);
    });

    it('leaves the recurring ones alone when opened on the yearly payments', () => {
        renderDialog({ initialStep: 2 });

        fireEvent.click(screen.getByRole('button', { name: 'I have no more' }));

        expect(postedAnswers()).toEqual({
            lines: [],
            dismissed: ['annual|checking|car insurance'],
            manual: [],
        });
    });

    it('goes back to step 1', () => {
        renderDialog();
        goToStep2();

        fireEvent.click(screen.getByRole('button', { name: 'Back' }));

        expect(
            screen.getByText('We found 4 movements that repeat'),
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
        router.post.mockReset();
    });

    it('shows why the answers could not be saved', () => {
        router.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onError: (errors: Record<string, string>) => void },
            ) =>
                options.onError({
                    lines: 'Some suggestions changed since you opened the review. Open it again to see them.',
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
        router.post.mockReset();
    });
});
