import {
    MAX_BACKOFF_MS,
    MAX_POLL_FAILURES,
    needsPolling,
    pollDelay,
    useImportPolling,
} from '@/hooks/use-import-polling';
import { type ImportStatus } from '@/types/full-import';
import { act, renderHook } from '@testing-library/react';
import { AxiosError, type AxiosResponse } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchImport = vi.hoisted(() => vi.fn());

vi.mock('@/lib/full-import-api', () => ({
    POLL_INTERVAL_MS: 1500,
    fetchImport,
}));

function status(overrides: Partial<ImportStatus> = {}): ImportStatus {
    return {
        id: 'import-1',
        source: 'banktrack',
        mode: 'add',
        status: 'processing',
        file_name: null,
        error: null,
        created_at: null,
        finished_at: null,
        undone_at: null,
        stats: { stage: 'transactions' },
        ...overrides,
    };
}

function httpError(code: number): AxiosError {
    return new AxiosError('failed', String(code), undefined, undefined, {
        status: code,
    } as AxiosResponse);
}

/** Let the pending timer fire and the request it started settle. */
async function tick(ms: number) {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
}

describe('needsPolling and pollDelay', () => {
    it('polls a running import and the AI pass after it, nothing else', () => {
        expect(needsPolling(null)).toBe(false);
        expect(needsPolling(status({ status: 'draft' }))).toBe(false);
        expect(needsPolling(status())).toBe(true);
        expect(
            needsPolling(
                status({
                    status: 'completed',
                    stats: { ai: { status: 'running' } },
                }),
            ),
        ).toBe(true);
        expect(
            needsPolling(
                status({
                    status: 'completed',
                    stats: { ai: { status: 'done' } },
                }),
            ),
        ).toBe(false);
    });

    it('backs off while the server does not answer, up to a ceiling', () => {
        expect(pollDelay(status(), 0)).toBe(1500);
        expect(pollDelay(status(), 1)).toBe(3000);
        expect(pollDelay(status(), 2)).toBe(6000);
        expect(pollDelay(status(), 10)).toBe(MAX_BACKOFF_MS);
    });
});

describe('useImportPolling', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        fetchImport.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('follows the import until it finishes', async () => {
        fetchImport.mockResolvedValue(status({ status: 'completed' }));
        const { result } = renderHook(() => useImportPolling());

        act(() => result.current.setStatus(status()));
        await tick(1500);

        expect(result.current.status?.status).toBe('completed');
        await tick(10_000);
        expect(fetchImport).toHaveBeenCalledTimes(1);
    });

    it('gives up after repeated failures, and starts again on retry', async () => {
        fetchImport.mockRejectedValue(new Error('offline'));
        const { result } = renderHook(() => useImportPolling());

        act(() => result.current.setStatus(status()));

        for (let attempt = 0; attempt < MAX_POLL_FAILURES; attempt++) {
            await tick(MAX_BACKOFF_MS);
        }

        expect(fetchImport).toHaveBeenCalledTimes(MAX_POLL_FAILURES);
        expect(result.current.problem).toBe('unreachable');

        await tick(MAX_BACKOFF_MS * 2);
        expect(fetchImport).toHaveBeenCalledTimes(MAX_POLL_FAILURES);

        fetchImport.mockResolvedValue(status({ status: 'completed' }));
        act(() => result.current.retry());
        await tick(1500);

        expect(result.current.problem).toBeNull();
        expect(result.current.status?.status).toBe('completed');
    });

    it('stops at once when the import is not there', async () => {
        fetchImport.mockRejectedValue(httpError(404));
        const { result } = renderHook(() => useImportPolling());

        act(() => result.current.setStatus(status()));
        await tick(1500);

        expect(result.current.problem).toBe('gone');
        await tick(MAX_BACKOFF_MS * 2);
        expect(fetchImport).toHaveBeenCalledTimes(1);
    });
});
