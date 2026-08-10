import { reloadOnChunkLoadError } from '@/lib/chunk-load-recovery';
import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AppErrorBoundary } from './app-error-boundary';

vi.mock('@/lib/chunk-load-recovery', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/chunk-load-recovery')>()),
    reloadOnChunkLoadError: vi.fn(),
}));

function Boom(): never {
    throw new Error('render exploded');
}

describe('AppErrorBoundary', () => {
    beforeEach(() => {
        // React logs the caught error; keep the test output readable.
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.restoreAllMocks();
        vi.mocked(reloadOnChunkLoadError).mockClear();
    });

    it('renders its children when nothing throws', () => {
        render(
            <AppErrorBoundary>
                <p>the app</p>
            </AppErrorBoundary>,
        );

        expect(screen.getByText('the app')).toBeInTheDocument();
    });

    it('shows a recoverable screen instead of a blank page when a child throws', () => {
        render(
            <AppErrorBoundary>
                <Boom />
            </AppErrorBoundary>,
        );

        expect(screen.getByText('Something went wrong.')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Try again' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Back to home' }),
        ).toBeInTheDocument();
    });

    it('hands the error to the chunk-load recovery, which the global listeners no longer see', () => {
        render(
            <AppErrorBoundary>
                <Boom />
            </AppErrorBoundary>,
        );

        expect(reloadOnChunkLoadError).toHaveBeenCalledWith(
            expect.objectContaining({ message: 'render exploded' }),
            expect.anything(),
        );
    });
});
