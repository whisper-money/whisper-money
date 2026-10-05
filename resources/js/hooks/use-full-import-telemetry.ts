import {
    outcomeProperties,
    trackImport,
    type ImportEntry,
} from '@/lib/full-import-telemetry';
import {
    type ImportHistoryEntry,
    type ImportStatus,
} from '@/types/full-import';
import { useEffect, useRef } from 'react';

/**
 * The full import's funnel events that hang off what the screen shows rather
 * than off a click. Each is guarded by a ref, so a re-render, a re-poll or a
 * development double effect never sends one twice.
 */

/** `full_import_opened`, once per wizard. */
export function useImportOpenedEvent(entry: ImportEntry): void {
    const sent = useRef(false);

    useEffect(() => {
        if (sent.current) {
            return;
        }

        sent.current = true;
        trackImport('full_import_opened', { entry });
    }, [entry]);
}

/** `full_import_step_viewed`, each time the screen changes step. */
export function useImportStepViewedEvent(
    step: string | null,
    entry: ImportEntry,
): void {
    const last = useRef<string | null>(null);

    useEffect(() => {
        if (step === null || last.current === step) {
            return;
        }

        last.current = step;
        trackImport('full_import_step_viewed', { step, entry });
    }, [step, entry]);
}

/**
 * `full_import_completed` or `full_import_failed`, once per import, however
 * many times the poll brings the finished status back (the AI pass after the
 * import keeps it polling). The duration is the server's when it has both
 * ends, otherwise from when this screen first saw the import.
 */
export function useImportOutcomeEvent(
    status: ImportStatus | null,
    entry: ImportEntry,
): void {
    const firstSeen = useRef<Map<string, number> | null>(null);
    const sent = useRef<Set<string> | null>(null);

    useEffect(() => {
        if (!status || status.status === 'draft') {
            return;
        }

        firstSeen.current ??= new Map();
        sent.current ??= new Set();

        if (!firstSeen.current.has(status.id)) {
            firstSeen.current.set(status.id, Date.now());
        }

        const finished =
            status.status === 'completed' || status.status === 'failed';

        if (!finished || sent.current.has(status.id)) {
            return;
        }

        sent.current.add(status.id);
        trackImport(
            status.status === 'completed'
                ? 'full_import_completed'
                : 'full_import_failed',
            outcomeProperties(
                status,
                firstSeen.current.get(status.id) ?? Date.now(),
                Date.now(),
                entry,
            ),
        );
    }, [status, entry]);
}

/**
 * `full_import_undone`, once per import, when the Settings list sees one
 * that was being undone come back undone.
 */
export function useImportUndoneEvents(imports: ImportHistoryEntry[]): void {
    const undoing = useRef<Set<string> | null>(null);
    const sent = useRef<Set<string> | null>(null);

    useEffect(() => {
        undoing.current ??= new Set();
        sent.current ??= new Set();

        for (const entry of imports) {
            if (entry.status === 'undoing') {
                undoing.current.add(entry.id);
                continue;
            }

            if (
                entry.undone_at &&
                undoing.current.has(entry.id) &&
                !sent.current.has(entry.id)
            ) {
                sent.current.add(entry.id);
                trackImport('full_import_undone', {
                    transactions: entry.stats.transactions?.imported ?? 0,
                });
            }
        }
    }, [imports]);
}
