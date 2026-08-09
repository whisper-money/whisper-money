import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { StepSyncing } from './step-syncing';

const { get } = vi.hoisted(() => ({ get: vi.fn() }));

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
});
