import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StepSyncing } from './step-syncing';

const { get, captureEvent } = vi.hoisted(() => ({
    get: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('axios', () => ({
    default: { get, isAxiosError: () => false },
}));

const reload = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: { reload: (...args: unknown[]) => reload(...args) },
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

/** A status payload with the counters the screen reads. */
function status(
    overrides: Record<string, unknown> = {},
    progress: Record<string, unknown> = {},
) {
    return {
        data: {
            pending: false,
            failed: false,
            bank: 'BBVA',
            progress: {
                transactions: 0,
                merchants: 0,
                accounts: 0,
                months: 0,
                first_date: null,
                last_date: null,
                ...progress,
            },
            ...overrides,
        },
    };
}

describe('StepSyncing', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
        get.mockReset();
        reload.mockReset();
        captureEvent.mockReset();
    });

    it('stops spinning and offers to continue when a connection failed', async () => {
        get.mockResolvedValue(status({ failed: true }));

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(
            screen.getByText('We couldn’t finish importing right now'),
        ).toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });

    // The counters are the whole point of the screen: a spinner cannot tell a
    // slow bank from a dead queue, and a number that moves can.
    it('shows what the bank has handed over so far, named after the bank', async () => {
        get.mockResolvedValue(
            status(
                { pending: true },
                { transactions: 903, merchants: 147, accounts: 4, months: 12 },
            ),
        );

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(screen.getByText('Reading your history')).toBeInTheDocument();
        expect(screen.getByText('903')).toBeInTheDocument();
        expect(screen.getByText('147')).toBeInTheDocument();
        expect(screen.getByText('12')).toBeInTheDocument();
        expect(screen.getByText('4')).toBeInTheDocument();
        expect(
            screen.getByText(
                'BBVA is handing over twelve months. This is the slow part — it’s their server, not ours.',
            ),
        ).toBeInTheDocument();
    });

    // A sync that stops is not a sync that broke, and the copy has to keep the
    // two apart: "nothing is broken" over a real failure would be a lie.
    it('gives up on a sync that never resolves, and says the bank is slow rather than broken', async () => {
        get.mockResolvedValue(
            status({ pending: true }, { transactions: 214, months: 3 }),
        );

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(screen.getByText('Reading your history')).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5 * 60_000 + 3_000);
        });

        expect(screen.getByText('BBVA is taking its time')).toBeInTheDocument();
        expect(screen.getByText('214 movements')).toBeInTheDocument();
        expect(
            screen.queryByText('Reading your history'),
        ).not.toBeInTheDocument();
        // The offer names what it actually has, rather than a fixed three months.
        expect(
            screen.getByRole('button', { name: 'Carry on with 3 months' }),
        ).toBeInTheDocument();
    });

    it('goes back to waiting when the user chooses to wait', async () => {
        get.mockResolvedValue(status({ pending: true }));

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5 * 60_000 + 3_000);
        });
        expect(screen.getByText('BBVA is taking its time')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', { name: 'Wait for the full year' }),
        );

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(screen.getByText('Reading your history')).toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });

    it('advances on its own once every connection has synced', async () => {
        get.mockResolvedValue(status());

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(reload).toHaveBeenCalled();
        expect(
            screen.queryByText('BBVA is taking its time'),
        ).not.toBeInTheDocument();
    });

    describe('analytics', () => {
        const outcomes = () =>
            captureEvent.mock.calls
                .filter(([name]) => name === 'onboarding_sync_outcome')
                .map(([, props]) => props);

        it('reports a sync that finished on its own', async () => {
            get.mockResolvedValue(status());

            render(<StepSyncing onComplete={vi.fn()} />);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(0);
            });

            expect(outcomes()).toEqual([
                { status: 'synced', waited_seconds: 0 },
            ]);
        });

        it('reports a connection that failed', async () => {
            get.mockResolvedValue(status({ failed: true }));

            render(<StepSyncing onComplete={vi.fn()} />);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(0);
            });

            expect(outcomes()).toEqual([
                { status: 'error', waited_seconds: 0 },
            ]);
        });

        // The known drop-out suspect: a stall, and then whether the user pressed
        // on or left. Both readings have to survive, so they are two events.
        it('reports the stall and the wait, then the user pressing on', async () => {
            get.mockResolvedValue(status({ pending: true }));

            render(<StepSyncing onComplete={vi.fn()} />);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(5 * 60_000 + 3_000);
            });

            expect(outcomes()).toEqual([
                { status: 'stuck', waited_seconds: 303 },
            ]);

            fireEvent.click(
                screen.getByRole('button', {
                    name: 'Carry on with what we have',
                }),
            );

            expect(outcomes()).toEqual([
                { status: 'stuck', waited_seconds: 303 },
                { status: 'continued_anyway', waited_seconds: 303 },
            ]);
        });

        // Choosing to keep waiting is the other half of the stall, and the one
        // that says the 5 minute cap fires too early.
        it('reports a user who chose to keep waiting', async () => {
            get.mockResolvedValue(status({ pending: true }));

            render(<StepSyncing onComplete={vi.fn()} />);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(5 * 60_000 + 3_000);
            });

            fireEvent.click(
                screen.getByRole('button', { name: 'Wait for the full year' }),
            );

            expect(outcomes().map((outcome) => outcome.status)).toEqual([
                'stuck',
                'waited',
            ]);
        });

        it('reports one outcome however many times it polled', async () => {
            get.mockResolvedValueOnce(status({ pending: true }))
                .mockResolvedValueOnce(status({ pending: true }))
                .mockResolvedValue(status());

            render(<StepSyncing onComplete={vi.fn()} />);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(10_000);
            });

            expect(outcomes()).toEqual([
                { status: 'synced', waited_seconds: 6 },
            ]);
        });
    });
});
