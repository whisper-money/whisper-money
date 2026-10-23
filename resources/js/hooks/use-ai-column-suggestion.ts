import {
    changedSuggestedFields,
    fetchColumnSuggestions,
    type ColumnSuggestions,
    type FieldMapping,
    type SuggestionKind,
} from '@/lib/ai-import-mapping';
import { type ColumnDescription } from '@/lib/column-shape';
import { captureEvent } from '@/lib/posthog';
import { type SharedData } from '@/types';
import { type UUID } from '@/types/uuid';
import { usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';

/** What the mapping step shows for the AI: nothing, a button, a spinner or the badge. */
export type AiSuggestionStatus = 'hidden' | 'idle' | 'loading' | 'suggested';

interface SuggestionRequest<M extends FieldMapping> {
    kind: SuggestionKind;
    columns: ColumnDescription[];
    accountId?: UUID | null;
    /** The AI's answer as a mapping to lay over the current one. */
    toMapping: (suggestions: ColumnSuggestions) => Partial<M>;
    /** Lay it over, leaving alone the fields in `keep`. */
    apply: (suggested: Partial<M>, keep: ReadonlySet<string>) => void;
    /** True for the button: the suggestion replaces every field, picked or not. */
    everything?: boolean;
}

/**
 * The AI column-mapping suggestion of one import: asking for it, keeping the
 * fields the user picks by hand out of a late answer, and telling whether the
 * mapping is still exactly what the AI suggested.
 */
export function useAiColumnSuggestion() {
    const { aiImportMappingEnabled: enabled } = usePage<SharedData>().props;
    const [suggested, setSuggested] = useState<FieldMapping | null>(null);
    const [cached, setCached] = useState(false);
    const [loading, setLoading] = useState(false);
    const touched = useRef(new Set<string>());
    // Bumped by every request and reset, so an answer for a file that is no
    // longer on screen is dropped.
    const generation = useRef(0);

    const reset = useCallback(() => {
        generation.current++;
        touched.current = new Set();
        setSuggested(null);
        setCached(false);
        setLoading(false);
    }, []);

    /** The user picked these fields by hand: a late suggestion leaves them be. */
    const touch = useCallback((...fields: string[]) => {
        fields.forEach((field) => touched.current.add(field));
    }, []);

    /** A stored mapping saved as the AI's suggestion, unchanged. */
    const adopt = useCallback((mapping: FieldMapping) => {
        setSuggested(mapping);
        setCached(true);
    }, []);

    const request = useCallback(
        async <M extends FieldMapping>({
            kind,
            columns,
            accountId,
            toMapping,
            apply,
            everything = false,
        }: SuggestionRequest<M>) => {
            if (!enabled) {
                return;
            }

            const current = ++generation.current;
            setLoading(true);

            const result = await fetchColumnSuggestions(
                kind,
                columns,
                accountId,
            );

            if (current !== generation.current) {
                return;
            }

            setLoading(false);

            if (!result) {
                return;
            }

            if (everything) {
                touched.current = new Set();
            }

            const mapping = toMapping(result);
            apply(mapping, new Set(touched.current));
            setSuggested(mapping as FieldMapping);
            setCached(result.cached);
        },
        [enabled],
    );

    const status = useCallback(
        (mapping: FieldMapping): AiSuggestionStatus => {
            if (!enabled) {
                return 'hidden';
            }

            if (loading) {
                return 'loading';
            }

            return suggested && changedSuggestedFields(mapping, suggested) === 0
                ? 'suggested'
                : 'idle';
        },
        [enabled, loading, suggested],
    );

    /** How many suggested fields the user changed, or null without a suggestion. */
    const changedFields = useCallback(
        (mapping: FieldMapping): number | null =>
            suggested ? changedSuggestedFields(mapping, suggested) : null,
        [suggested],
    );

    /**
     * Count an import that went through with a suggestion on screen: how many
     * of the suggested fields the user changed first, and whether the
     * suggestion was served from the cache.
     */
    const trackImported = useCallback(
        (mapping: FieldMapping, entry: string, kind: SuggestionKind) => {
            if (!suggested) {
                return;
            }

            captureEvent('import_ai_mapping_imported', {
                entry,
                kind,
                suggested_fields: Object.keys(suggested).length,
                changed_fields: changedSuggestedFields(mapping, suggested),
                cached,
            });
        },
        [suggested, cached],
    );

    return {
        enabled,
        cached,
        trackImported,
        request,
        reset,
        touch,
        adopt,
        status,
        changedFields,
    };
}
