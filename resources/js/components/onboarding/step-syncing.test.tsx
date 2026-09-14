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
}));

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
        get.mockResolvedValue({ data: { pending: false, failed: true } });

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(
            screen.getByText('We couldn’t finish importing right now'),
        ).toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });

    it('gives up on a sync that never resolves instead of polling forever', async () => {
        get.mockResolvedValue({ data: { pending: true, failed: false } });

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(
            screen.getByText('This will only take a moment.'),
        ).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(5 * 60_000 + 3_000);
        });

        expect(
            screen.getByText('We couldn’t finish importing right now'),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('This will only take a moment.'),
        ).not.toBeInTheDocument();
    });

    it('advances on its own once every connection has synced', async () => {
        get.mockResolvedValue({ data: { pending: false, failed: false } });

        render(<StepSyncing onComplete={vi.fn()} />);

        await act(async () => {
            await vi.advanceTimersByTimeAsync(0);
        });

        expect(reload).toHaveBeenCalled();
        expect(
            screen.queryByText('We couldn’t finish importing right now'),
        ).not.toBeInTheDocument();
    });

    describe('analytics', () => {
        const outcomes = () =>
            captureEvent.mock.calls
                .filter(([name]) => name === 'onboarding_sync_outcome')
                .map(([, props]) => props);

        it('reports a sync that finished on its own', async () => {
            get.mockResolvedValue({ data: { pending: false, failed: false } });

            render(<StepSyncing onComplete={vi.fn()} />);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(0);
            });

            expect(outcomes()).toEqual([
                { status: 'synced', waited_seconds: 0 },
            ]);
        });

        it('reports a connection that failed', async () => {
            get.mockResolvedValue({ data: { pending: false, failed: true } });

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
            get.mockResolvedValue({ data: { pending: true, failed: false } });

            render(<StepSyncing onComplete={vi.fn()} />);

            await act(async () => {
                await vi.advanceTimersByTimeAsync(5 * 60_000 + 3_000);
            });

            expect(outcomes()).toEqual([
                { status: 'stuck', waited_seconds: 303 },
            ]);

            fireEvent.click(screen.getByRole('button'));

            expect(outcomes()).toEqual([
                { status: 'stuck', waited_seconds: 303 },
                { status: 'continued_anyway', waited_seconds: 303 },
            ]);
        });

        it('reports one outcome however many times it polled', async () => {
            get.mockResolvedValueOnce({
                data: { pending: true, failed: false },
            })
                .mockResolvedValueOnce({
                    data: { pending: true, failed: false },
                })
                .mockResolvedValue({ data: { pending: false, failed: false } });

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
