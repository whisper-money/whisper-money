import { act, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepReveal } from './step-reveal';

const { get, captureEvent } = vi.hoisted(() => ({
    get: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('axios', () => ({
    default: { get, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

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
            ...overrides,
        },
    };
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
        captureEvent.mockReset();
    });

    it('puts the guess beside the number, and the merchants under it', async () => {
        get.mockResolvedValue(spending());

        await renderReveal({ spendingGuess: 120000 });

        expect(screen.getByText('1,847')).toBeInTheDocument();
        expect(screen.getByText('€1,200')).toBeInTheDocument();
        expect(screen.getByText('MERCADONA')).toBeInTheDocument();
        expect(
            screen.getByText('Sort these 147 merchants'),
        ).toBeInTheDocument();
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
            screen.getByText('Next we turn all 147 of them into categories.'),
        ).toBeInTheDocument();
    });

    it('shows what someone with no spending is worth, and offers the other half', async () => {
        get.mockResolvedValue({
            data: {
                variant: 'assets',
                currency_code: 'EUR',
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
});
