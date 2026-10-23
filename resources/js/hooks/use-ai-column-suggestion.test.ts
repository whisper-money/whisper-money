import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAiColumnSuggestion } from './use-ai-column-suggestion';

const props = { aiImportMappingEnabled: true };

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props }),
}));

const { fetchColumnSuggestions, captureEvent } = vi.hoisted(() => ({
    fetchColumnSuggestions: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/ai-import-mapping', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@/lib/ai-import-mapping')>()),
    fetchColumnSuggestions,
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

type Mapping = { amount: string | null; description: string | null };

const answer = {
    cached: false,
    fields: {
        amount: { column: 'Amount', index: 1, confidence: 0.9 },
        description: { column: 'Narrative', index: 0, confidence: 0.9 },
    },
};

const toMapping = () => ({ amount: 'Amount', description: 'Narrative' });

/** A pending request the test settles when it wants. */
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => (resolve = done));

    return { promise, resolve };
}

describe('useAiColumnSuggestion', () => {
    beforeEach(() => {
        props.aiImportMappingEnabled = true;
        fetchColumnSuggestions.mockReset();
        captureEvent.mockReset();
    });

    it('stays hidden and never asks when AI column mapping is off', async () => {
        props.aiImportMappingEnabled = false;
        const apply = vi.fn();
        const { result } = renderHook(() => useAiColumnSuggestion());

        await act(() =>
            result.current.request<Mapping>({
                kind: 'transaction',
                columns: [],
                toMapping,
                apply,
            }),
        );

        expect(result.current.status({ amount: null, description: null })).toBe(
            'hidden',
        );
        expect(fetchColumnSuggestions).not.toHaveBeenCalled();
        expect(apply).not.toHaveBeenCalled();
    });

    it('goes from the button to the badge once the suggestion is applied', async () => {
        const pending = deferred<typeof answer>();
        fetchColumnSuggestions.mockReturnValue(pending.promise);
        const apply = vi.fn();
        const { result } = renderHook(() => useAiColumnSuggestion());
        const blank = { amount: null, description: null };

        expect(result.current.status(blank)).toBe('idle');

        let request!: Promise<void>;
        act(() => {
            request = result.current.request<Mapping>({
                kind: 'transaction',
                columns: [],
                toMapping,
                apply,
            });
        });

        expect(result.current.status(blank)).toBe('loading');

        await act(async () => {
            pending.resolve(answer);
            await request;
        });

        expect(apply).toHaveBeenCalledWith(toMapping(), new Set());
        expect(result.current.status(toMapping())).toBe('suggested');
        // Editing a field after the suggestion turns the badge back into the button.
        expect(
            result.current.status({
                amount: 'Balance',
                description: 'Narrative',
            }),
        ).toBe('idle');
    });

    it('leaves the fields picked by hand out of a late answer', async () => {
        fetchColumnSuggestions.mockResolvedValue(answer);
        const apply = vi.fn();
        const { result } = renderHook(() => useAiColumnSuggestion());

        result.current.touch('description');
        await act(() =>
            result.current.request<Mapping>({
                kind: 'transaction',
                columns: [],
                toMapping,
                apply,
            }),
        );

        expect(apply).toHaveBeenCalledWith(
            toMapping(),
            new Set(['description']),
        );
    });

    it('applies the button to every field, picked or not', async () => {
        fetchColumnSuggestions.mockResolvedValue(answer);
        const apply = vi.fn();
        const { result } = renderHook(() => useAiColumnSuggestion());

        result.current.touch('description');
        await act(() =>
            result.current.request<Mapping>({
                kind: 'transaction',
                columns: [],
                toMapping,
                apply,
                everything: true,
            }),
        );

        expect(apply).toHaveBeenCalledWith(toMapping(), new Set());
    });

    it('drops an answer for a file no longer on screen', async () => {
        const pending = deferred<typeof answer>();
        fetchColumnSuggestions.mockReturnValue(pending.promise);
        const apply = vi.fn();
        const { result } = renderHook(() => useAiColumnSuggestion());

        let request!: Promise<void>;
        act(() => {
            request = result.current.request<Mapping>({
                kind: 'transaction',
                columns: [],
                toMapping,
                apply,
            });
        });
        act(() => result.current.reset());

        await act(async () => {
            pending.resolve(answer);
            await request;
        });

        expect(apply).not.toHaveBeenCalled();
        expect(result.current.status(toMapping())).toBe('idle');
    });

    it('keeps the heuristic silently when the AI has no answer', async () => {
        fetchColumnSuggestions.mockResolvedValue(null);
        const apply = vi.fn();
        const { result } = renderHook(() => useAiColumnSuggestion());

        await act(() =>
            result.current.request<Mapping>({
                kind: 'transaction',
                columns: [],
                toMapping,
                apply,
            }),
        );

        expect(apply).not.toHaveBeenCalled();
        expect(result.current.status(toMapping())).toBe('idle');
    });

    it('shows the badge for a stored layout saved as the suggestion', () => {
        const { result } = renderHook(() => useAiColumnSuggestion());

        act(() => result.current.adopt(toMapping()));

        expect(result.current.status(toMapping())).toBe('suggested');
    });

    it('counts how many suggested fields were changed before importing', async () => {
        fetchColumnSuggestions.mockResolvedValue({ ...answer, cached: true });
        const { result } = renderHook(() => useAiColumnSuggestion());

        await act(() =>
            result.current.request<Mapping>({
                kind: 'transaction',
                columns: [],
                toMapping,
                apply: vi.fn(),
            }),
        );
        result.current.trackImported(
            { amount: 'Balance', description: 'Narrative' },
            'account_drawer',
            'transaction',
        );

        expect(captureEvent).toHaveBeenCalledWith(
            'import_ai_mapping_imported',
            {
                entry: 'account_drawer',
                kind: 'transaction',
                suggested_fields: 2,
                changed_fields: 1,
                cached: true,
            },
        );
    });

    it('tracks nothing without a suggestion', () => {
        const { result } = renderHook(() => useAiColumnSuggestion());

        result.current.trackImported(
            toMapping(),
            'account_drawer',
            'transaction',
        );

        expect(captureEvent).not.toHaveBeenCalled();
    });
});
