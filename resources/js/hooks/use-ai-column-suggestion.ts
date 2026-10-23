import {
    changedSuggestedFields,
    fetchColumnSuggestions,
    pickFields,
    type ColumnSuggestions,
    type FieldMapping,
    type SuggestionKind,
} from '@/lib/ai-import-mapping';
import { type ColumnDescription } from '@/lib/column-shape';
import { captureEvent } from '@/lib/posthog';
import { type SharedData } from '@/types';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';

/** What the mapping step shows for the AI: nothing, a button, a spinner or the badge. */
export type AiSuggestionStatus = 'hidden' | 'idle' | 'loading' | 'suggested';

interface SuggestionRequest<M extends FieldMapping> {
    kind: SuggestionKind;
    columns: ColumnDescription[];
    accountId?: UUID | null;
    /** The file it is asked for, from {@link begin}; the file on screen when left out. */
    token?: number;
    /** The AI's answer as a mapping to lay over the current one. */
    toMapping: (suggestions: ColumnSuggestions) => Partial<M>;
    /** Lay it over, leaving alone the fields in `keep`. */
    apply: (suggested: Partial<M>, keep: ReadonlySet<string>) => void;
    /** True for the button: the suggestion replaces every field, picked or not. */
    everything?: boolean;
}

/** A layout stored for the account, as far as the AI is concerned. */
interface StoredLayout {
    mapping: FieldMapping;
    aiSuggested?: boolean;
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
    // One per file picked: whatever was started for an earlier file, a late
    // answer or a late stored layout, is dropped once it no longer matches.
    const generation = useRef(0);

    /** Start over for a new file, and get the token its later calls carry. */
    const begin = useCallback((): number => {
        touched.current = new Set();
        setSuggested(null);
        setCached(false);
        setLoading(false);

        return ++generation.current;
    }, []);

    /** The user picked these fields by hand: a late suggestion leaves them be. */
    const touch = useCallback((...fields: string[]) => {
        fields.forEach((field) => touched.current.add(field));
    }, []);

    const request = useCallback(
        async <M extends FieldMapping>({
            kind,
            columns,
            accountId,
            token = generation.current,
            toMapping,
            apply,
            everything = false,
        }: SuggestionRequest<M>) => {
            if (!enabled || token !== generation.current) {
                return;
            }

            setLoading(true);

            const result = await fetchColumnSuggestions(
                kind,
                columns,
                accountId,
            );

            if (token !== generation.current) {
                return;
            }

            setLoading(false);

            // Asked for on purpose, a failure is worth a word; asked for on
            // its own, the header guess simply stays.
            if (!result) {
                if (everything) {
                    toast.error(
                        __(
                            'The AI could not read the columns. Try again later.',
                        ),
                    );
                }

                return;
            }

            if (everything) {
                touched.current = new Set();
            }

            const mapping = toMapping(result);
            apply(mapping, new Set(touched.current));
            setSuggested(mapping);
            setCached(result.cached);
        },
        [enabled],
    );

    /**
     * What the account's stored layout means for the AI: none fits the file,
     * so it is asked; one saved as its suggestion shows the badge.
     */
    const afterStoredLayout = useCallback(
        (
            token: number,
            stored: StoredLayout | null,
            fields: readonly string[],
            ask: () => void,
        ) => {
            if (token !== generation.current) {
                return;
            }

            if (!stored) {
                ask();

                return;
            }

            if (stored.aiSuggested) {
                setSuggested(pickFields(stored.mapping, fields));
                setCached(true);
            }
        },
        [],
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

    /**
     * An import went through with this mapping: count it (how many suggested
     * fields the user changed first, and whether the suggestion came from the
     * cache) and say whether the mapping is the suggestion, unchanged — what
     * the stored layout is flagged with.
     */
    const importedWith = useCallback(
        (
            mapping: FieldMapping,
            entry: string,
            kind: SuggestionKind,
        ): boolean => {
            if (!suggested) {
                return false;
            }

            const changed = changedSuggestedFields(mapping, suggested);

            captureEvent('import_ai_mapping_imported', {
                entry,
                kind,
                suggested_fields: Object.keys(suggested).length,
                changed_fields: changed,
                cached,
            });

            return changed === 0;
        },
        [suggested, cached],
    );

    return {
        begin,
        touch,
        request,
        afterStoredLayout,
        status,
        importedWith,
    };
}
