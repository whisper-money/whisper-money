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

    it('answers the guess with the gap and what it costs in a year', async () => {
        get.mockResolvedValue(spending());

        await renderReveal({ spendingGuess: 120000 });

        expect(screen.getByText('1,847')).toBeInTheDocument();
        expect(screen.getByText('€647')).toBeInTheDocument();
        expect(screen.getByText('€7,764')).toBeInTheDocument();
        expect(screen.getByText('MERCADONA')).toBeInTheDocument();
        expect(
            screen.getByText('Sort these 147 merchants'),
        ).toBeInTheDocument();
    });

    it('does not tell someone who spent less than they feared to account for it', async () => {
        get.mockResolvedValue(spending({ spent: 100000 }));

        await renderReveal({ spendingGuess: 120000 });

        expect(
            screen.getByText(/almost nobody misses this way/),
        ).toBeInTheDocument();
        expect(screen.queryByText(/you can’t account for/)).toBeNull();
    });

    it('drops the comparison when there is no guess to compare against', async () => {
        get.mockResolvedValue(spending());

        await renderReveal();

        expect(screen.getByText(/a year, at the rate of/)).toBeInTheDocument();
        expect(screen.queryByText(/You guessed/)).toBeNull();
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
