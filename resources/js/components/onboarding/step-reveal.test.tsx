import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepReveal } from './step-reveal';

const { get, post, captureEvent } = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('axios', () => ({
    default: { get, post, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: { locale: 'en-US', auth: { user: { currency_code: 'EUR' } } },
    }),
}));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

/** The spending payload, with the month it is written about already current. */
function spending(overrides: Record<string, unknown> = {}) {
    return {
        data: {
            variant: 'spending',
            currency_code: 'EUR',
            month: '2026-08',
            is_last_month: true,
            is_partial: false,
            spent: 184700,
            merchants: [
                { name: 'MERCADONA', amount: 31200 },
                { name: 'GLOVO', amount: 14800 },
            ],
            merchant_count: 147,
            recurring_count: 9,
            is_estimate: false,
            ...overrides,
        },
    };
}

/** A month made only of the round figures the user typed in. */
function estimate(overrides: Record<string, unknown> = {}) {
    return spending({
        spent: 124000,
        is_estimate: true,
        merchants: [{ name: 'Rent', amount: 75000 }],
        estimates: [
            { name: 'Rent', amount: 75000 },
            { name: 'Groceries', amount: 49000 },
        ],
        ...overrides,
    });
}

/** The assets payload of a reader whose current account gave us no month. */
function awaitingMovements(overrides: Record<string, unknown> = {}) {
    return {
        data: {
            variant: 'assets',
            currency_code: 'EUR',
            has_spending_accounts: true,
            quick_expenses_account: {
                id: 'a1',
                name: 'Main account',
                currency_code: 'EUR',
            },
            net_worth: 1240000,
            accounts: [
                {
                    id: 'a1',
                    name: 'Main account',
                    connected: false,
                    balance: 1240000,
                },
            ],
            ...overrides,
        },
    };
}

/** Let a pending request resolve. */
async function settle() {
    await act(async () => {
        await Promise.resolve();
    });
}

/** Render and let the request that fills the screen resolve. */
async function renderReveal(props: Record<string, unknown> = {}) {
    render(
        <StepReveal
            onContinue={vi.fn()}
            onAddAccount={vi.fn()}
            {...(props as { onContinue: () => void; onAddAccount: () => void })}
        />,
    );

    await act(async () => {
        await Promise.resolve();
    });
}

describe('StepReveal', () => {
    afterEach(() => {
        get.mockReset();
        post.mockReset();
        captureEvent.mockReset();
    });

    it('puts the guess beside the number, and the merchants under it', async () => {
        get.mockResolvedValue(spending());

        await renderReveal({ spendingGuess: 120000 });

        expect(screen.getByText('1,847')).toBeInTheDocument();
        expect(screen.getByText('€1,200')).toBeInTheDocument();
        expect(screen.getByText('MERCADONA')).toBeInTheDocument();
        expect(screen.getByText('Sort my merchants')).toBeInTheDocument();
    });

    /**
     * Nothing is categorized this early, so the figure carries the user's own
     * transfers — about a third of what leaves an account, measured. Read as a
     * verdict it told half of them they had spending they could not account
     * for, and the money was theirs, moved.
     */
    it('says what the number includes instead of reading a verdict into it', async () => {
        get.mockResolvedValue(spending());

        await renderReveal({ spendingGuess: 120000 });

        expect(
            screen.getByText(/money you moved to your own accounts/),
        ).toBeInTheDocument();
        expect(screen.queryByText(/you can’t account for/)).toBeNull();
        expect(screen.queryByText(/a year/)).toBeNull();
    });

    it('accuses nobody who came in under their guess either', async () => {
        get.mockResolvedValue(spending({ spent: 100000 }));

        await renderReveal({ spendingGuess: 120000 });

        expect(screen.queryByText(/almost nobody misses this way/)).toBeNull();
        expect(
            screen.getByText(/money you moved to your own accounts/),
        ).toBeInTheDocument();
    });

    it('drops the comparison when there is no guess to compare against', async () => {
        get.mockResolvedValue(spending());

        await renderReveal();

        expect(
            screen.getByText(
                'Everything that left the account — money you moved to your own accounts included.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByText(/You guessed/)).toBeNull();
    });

    it('does not set a month still running against the guess', async () => {
        get.mockResolvedValue(
            spending({
                month: '2026-09',
                is_last_month: false,
                is_partial: true,
                spent: 8100,
            }),
        );

        await renderReveal({ spendingGuess: 120000 });

        expect(screen.getByText('This month so far')).toBeInTheDocument();
        expect(screen.getByText(/isn’t over/)).toBeInTheDocument();
        // Neither half of the verdict holds on a month that has not finished.
        expect(screen.queryByText(/You guessed/)).toBeNull();
        expect(screen.queryByText(/a year/)).toBeNull();
    });

    it('names the month when the import is not last month’s', async () => {
        get.mockResolvedValue(
            spending({ month: '2026-03', is_last_month: false }),
        );

        await renderReveal({ spendingGuess: 120000 });

        expect(screen.getByText('March 2026')).toBeInTheDocument();
    });

    it('keeps quiet about repeat charges when there is barely one', async () => {
        get.mockResolvedValue(spending({ recurring_count: 1 }));

        await renderReveal({ spendingGuess: 120000 });

        expect(screen.queryByText(/charged you the same amount/)).toBeNull();
        // And having named none, it cannot point at them either.
        expect(screen.queryByText(/repeat charges/)).toBeNull();
        expect(
            screen.getByText('Next we turn them into categories.'),
        ).toBeInTheDocument();
    });

    it('shows what someone with no spending is worth, and offers the other half', async () => {
        get.mockResolvedValue({
            data: {
                variant: 'assets',
                currency_code: 'EUR',
                has_spending_accounts: false,
                net_worth: 3124000,
                accounts: [
                    {
                        id: 'a1',
                        name: 'Plan de pensiones',
                        connected: false,
                        balance: 1840000,
                    },
                    {
                        id: 'a2',
                        name: 'Indexa Capital',
                        connected: true,
                        balance: 1489000,
                    },
                ],
            },
        });

        await renderReveal();

        expect(screen.getByText('You’re worth €31,240')).toBeInTheDocument();
        expect(screen.getByText('Added by hand')).toBeInTheDocument();
        expect(screen.getByText('Connected')).toBeInTheDocument();
        expect(screen.getByText('Add a current account')).toBeInTheDocument();
    });

    it('moves on rather than showing a screen with nothing on it', async () => {
        get.mockResolvedValue({
            data: {
                variant: 'assets',
                currency_code: 'EUR',
                has_spending_accounts: false,
                net_worth: 0,
                accounts: [],
            },
        });
        const onContinue = vi.fn();

        await renderReveal({ onContinue });

        expect(onContinue).toHaveBeenCalled();
    });

    it('moves on when the numbers behind the screen never arrive', async () => {
        get.mockRejectedValue(new Error('offline'));
        const onContinue = vi.fn();

        await renderReveal({ onContinue });

        expect(onContinue).toHaveBeenCalled();
    });

    // The bank handed over movements, none of them outgoing — so this reader
    // already holds the current account the other variant offers them.
    it('does not offer a current account to someone who just connected three', async () => {
        get.mockResolvedValue({
            data: {
                variant: 'assets',
                currency_code: 'EUR',
                has_spending_accounts: true,
                net_worth: 836308,
                accounts: [
                    {
                        id: 'a1',
                        name: 'Nombre Apellido1',
                        connected: true,
                        balance: 725129,
                    },
                ],
            },
        });

        await renderReveal();

        expect(screen.getByText('You’re worth €8,363')).toBeInTheDocument();
        expect(screen.queryByText('Add a current account')).toBeNull();
        expect(screen.queryByText(/move as single numbers/)).toBeNull();
        expect(
            screen.getByText(/Not enough has left these accounts yet/),
        ).toBeInTheDocument();
        expect(screen.getByText('Continue')).toBeInTheDocument();
    });

    it('reads a month of estimates back as the user’s basics, against the guess', async () => {
        get.mockResolvedValue(estimate());

        await renderReveal({ spendingGuess: 200000 });

        expect(screen.getByText('August · your estimate')).toBeInTheDocument();
        expect(
            screen.getByText('Your basics come to €1,240 a month'),
        ).toBeInTheDocument();
        expect(screen.getByText(/The other/)).toHaveTextContent(
            'You guessed €2,000. The other €760 is the part nobody remembers',
        );
        expect(
            screen.getByRole('img', {
                name: 'Basics €1,240 of a €2,000 guess; €760 not found yet',
            }),
        ).toBeInTheDocument();
        expect(screen.getByText('Not found yet €760')).toBeInTheDocument();
        expect(screen.getByText('Groceries')).toBeInTheDocument();
        expect(screen.getByText('€490')).toBeInTheDocument();
        expect(screen.getByText(/Saved as estimates/)).toBeInTheDocument();
        // None of the bank-month wording, which the figures cannot back.
        expect(screen.queryByText('Sort my merchants')).toBeNull();
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_reveal_shown',
            expect.objectContaining({ variant: 'spending', is_estimate: true }),
        );
    });

    it('draws no gap when the estimates already cover the guess', async () => {
        get.mockResolvedValue(estimate());

        await renderReveal({ spendingGuess: 100000 });

        expect(
            screen.getByText(
                'That’s what you typed for last month. Your real movements will fill in the rest.',
            ),
        ).toBeInTheDocument();
        expect(screen.queryByRole('img')).toBeNull();
    });

    it('draws no gap without a guess', async () => {
        get.mockResolvedValue(estimate());

        await renderReveal();

        expect(screen.getByText(/That’s what you typed/)).toBeInTheDocument();
        expect(screen.queryByRole('img')).toBeNull();
    });

    it('reports a bank month as no estimate', async () => {
        get.mockResolvedValue(spending());

        await renderReveal({ spendingGuess: 120000 });

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_reveal_shown',
            expect.objectContaining({ is_estimate: false }),
        );
    });

    it('offers a reader with no month to type last month in, guess beside it', async () => {
        get.mockResolvedValue(awaitingMovements());

        await renderReveal({ spendingGuess: 200000 });

        expect(screen.getByText(/That’s the balance side/)).toBeInTheDocument();
        expect(screen.getByText('You guessed')).toBeInTheDocument();
        expect(screen.getByText('€2,000 a month')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Two minutes of round figures for Main account and we can tell you how close you were.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Add last month in round figures'),
        ).toBeInTheDocument();
        expect(screen.getByText('Continue without it')).toBeInTheDocument();
    });

    it('opens the round figures in place, and reads the reveal again after', async () => {
        get.mockResolvedValueOnce(awaitingMovements());
        const onContinue = vi.fn();

        await renderReveal({ spendingGuess: 200000, onContinue });

        fireEvent.click(screen.getByText('Add last month in round figures'));

        expect(
            screen.getByText('Last month, in round figures'),
        ).toBeInTheDocument();
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_quick_expenses_opened',
            { source: 'reveal' },
        );

        // Back leaves the reveal as it was, with nothing asked again.
        fireEvent.click(screen.getByText('Back'));
        expect(screen.getByText('Continue without it')).toBeInTheDocument();
        expect(get).toHaveBeenCalledTimes(1);

        fireEvent.click(screen.getByText('Add last month in round figures'));
        fireEvent.change(screen.getByLabelText('Rent'), {
            target: { value: '750' },
        });
        post.mockResolvedValueOnce({ data: {} });
        get.mockResolvedValueOnce(estimate());
        fireEvent.click(screen.getByRole('button', { name: 'Save 1 expense' }));
        await settle();
        await settle();

        expect(get).toHaveBeenCalledTimes(2);
        expect(
            screen.getByText('Your basics come to €1,240 a month'),
        ).toBeInTheDocument();
        expect(onContinue).not.toHaveBeenCalled();
    });

    // Skipping is the same answer as "Continue without it", so it is not
    // asked a second time.
    it('moves on when the round figures are skipped', async () => {
        get.mockResolvedValue(awaitingMovements());
        const onContinue = vi.fn();

        await renderReveal({ spendingGuess: 200000, onContinue });

        fireEvent.click(screen.getByText('Add last month in round figures'));
        fireEvent.click(screen.getByText('Skip for now'));

        expect(onContinue).toHaveBeenCalled();
        expect(get).toHaveBeenCalledTimes(1);
    });

    it('promises no comparison when the account is in another currency', async () => {
        get.mockResolvedValue(
            awaitingMovements({
                quick_expenses_account: {
                    id: 'a1',
                    name: 'Main account',
                    currency_code: 'USD',
                },
            }),
        );

        await renderReveal({ spendingGuess: 200000 });

        expect(screen.queryByText('You guessed')).toBeNull();
        expect(
            screen.getByText('Add last month in round figures'),
        ).toBeInTheDocument();
    });

    it('keeps the plain card-free offer when no guess was made', async () => {
        get.mockResolvedValue(awaitingMovements());

        await renderReveal();

        expect(screen.queryByText('You guessed')).toBeNull();
        expect(
            screen.getByText('Add last month in round figures'),
        ).toBeInTheDocument();
    });

    it('offers no round figures when every account is a bank’s', async () => {
        get.mockResolvedValue(
            awaitingMovements({ quick_expenses_account: null }),
        );

        await renderReveal({ spendingGuess: 200000 });

        expect(
            screen.queryByText('Add last month in round figures'),
        ).toBeNull();
        expect(screen.queryByText('You guessed')).toBeNull();
        expect(screen.getByText('Continue')).toBeInTheDocument();
    });
});
