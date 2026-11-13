import { type WidgetFrame } from '@/types/dashboard';
import { useCallback, useState } from 'react';

/** The card the dashboard draws around a user widget. */
export const WIDGET_CARD_CLASS_NAME =
    'rounded-xl border bg-card text-card-foreground';

/**
 * Whether a widget is drawn in its card: always, unless its manifest asks for
 * no frame, and even then while it loads and once it fails, so a cell is never
 * left empty on the dashboard's background.
 */
export function isWidgetCarded(
    frame: WidgetFrame | undefined,
    showsWidget: boolean,
): boolean {
    return frame !== 'none' || !showsWidget;
}

/**
 * Whether the widget threw while rendering with `resetKey`: the error
 * boundary tries again when it changes, and so does this.
 */
export function useWidgetCrash(resetKey: unknown): {
    crashed: boolean;
    markCrashed: () => void;
} {
    const [crashedAt, setCrashedAt] = useState<{ key: unknown } | null>(null);
    const markCrashed = useCallback(
        () => setCrashedAt({ key: resetKey }),
        [resetKey],
    );

    return { crashed: crashedAt?.key === resetKey, markCrashed };
}
