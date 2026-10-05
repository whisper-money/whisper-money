import {
    useImportOpenedEvent,
    useImportOutcomeEvent,
    useImportStepViewedEvent,
    useImportUndoneEvents,
} from '@/hooks/use-full-import-telemetry';
import { importStatus } from '@/lib/full-import.fixture';
import { type ImportHistoryEntry } from '@/types/full-import';
import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const captureEvent = vi.hoisted(() => vi.fn());

vi.mock('@/lib/posthog', () => ({ captureEvent }));

const status = importStatus;

function eventsNamed(name: string) {
    return captureEvent.mock.calls.filter(([event]) => event === name);
}

describe('full import telemetry hooks', () => {
    beforeEach(() => captureEvent.mockReset());

    it('sends the opened event once, however often the wizard renders', () => {
        const { rerender } = renderHook(() => useImportOpenedEvent('settings'));

        rerender();
        rerender();

        expect(eventsNamed('full_import_opened')).toEqual([
            ['full_import_opened', { entry: 'settings' }],
        ]);
    });

    it('sends a step view per step change, not per render', () => {
        const { rerender } = renderHook(
            ({ step }) => useImportStepViewedEvent(step, 'onboarding'),
            { initialProps: { step: 'file' as string | null } },
        );

        rerender({ step: 'file' });
        rerender({ step: 'columns' });
        rerender({ step: 'columns' });

        expect(
            eventsNamed('full_import_step_viewed').map(([, props]) => props),
        ).toEqual([
            { step: 'file', entry: 'onboarding' },
            { step: 'columns', entry: 'onboarding' },
        ]);
    });

    it('sends completed once across re-polls of the same import', () => {
        const { rerender } = renderHook(
            ({ current }) => useImportOutcomeEvent(current, 'settings'),
            { initialProps: { current: status() } },
        );

        const done = {
            status: 'completed' as const,
            stats: { ai: { status: 'queued' as const } },
        };
        rerender({ current: status(done) });
        rerender({ current: status(done) });
        rerender({
            current: status({ ...done, stats: { ai: { status: 'done' } } }),
        });

        expect(eventsNamed('full_import_completed')).toHaveLength(1);
        expect(eventsNamed('full_import_failed')).toHaveLength(0);
    });

    it('sends failed for an import that stopped, even when first seen finished', () => {
        renderHook(() =>
            useImportOutcomeEvent(status({ status: 'failed' }), 'onboarding'),
        );

        expect(eventsNamed('full_import_failed')).toHaveLength(1);
    });

    it('sends undone once, when an import being undone comes back undone', () => {
        const entry = (
            overrides: Partial<ImportHistoryEntry>,
        ): ImportHistoryEntry => ({
            ...status({
                status: 'completed',
                stats: {
                    transactions: {
                        total: 5,
                        processed: 5,
                        imported: 5,
                        duplicates: 0,
                        skipped: 0,
                    },
                },
            }),
            undoable: false,
            summary: null,
            ...overrides,
        });
        const { rerender } = renderHook(
            ({ imports }) => useImportUndoneEvents(imports),
            { initialProps: { imports: [entry({ status: 'undoing' })] } },
        );

        rerender({ imports: [entry({ undone_at: '2026-10-05T10:00:00Z' })] });
        rerender({ imports: [entry({ undone_at: '2026-10-05T10:00:00Z' })] });

        expect(eventsNamed('full_import_undone')).toEqual([
            ['full_import_undone', { transactions: 5 }],
        ]);
    });
});
