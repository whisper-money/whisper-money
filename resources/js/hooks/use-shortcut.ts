import { usePlatform } from '@/hooks/use-platform';
import { formatCombo, parseCombo, toAriaKeyShortcuts } from '@/lib/key-combo';
import { SHORTCUTS, type ShortcutId } from '@/lib/shortcut-catalog';
import { ROOT_LAYER, shortcutRegistry } from '@/lib/shortcut-registry';
import { createContext, useContext, useEffect, useId, useRef } from 'react';

/**
 * The layer a component's shortcuts belong to: the nearest dialog, drawer or
 * sheet around it, or the page.
 */
export const ShortcutLayerContext = createContext<string>(ROOT_LAYER);

/**
 * Opens a layer on top of the stack while `active`. A modal layer keeps keys
 * from the layers beneath it, the page included. Provide the id it returns
 * through `ShortcutLayerContext`, or use `<ShortcutLayer>`.
 */
export function useShortcutLayer({
    active = true,
    modal = true,
}: { active?: boolean; modal?: boolean } = {}): string {
    const parent = useContext(ShortcutLayerContext);
    const id = useId();

    useEffect(() => {
        if (!active) {
            return;
        }

        return shortcutRegistry.activateLayer(id, { parent, modal });
    }, [id, parent, modal, active]);

    return id;
}

/**
 * Runs `handler` when the shortcut's keys are pressed and its layer is the one
 * on top. The handler is read when the key fires, so passing a new function on
 * every render does not register the shortcut again.
 *
 * It registers on the layer of the component that calls it: a component that
 * renders its own dialog registers the dialog's shortcuts with `<Shortcut>`,
 * placed inside `DialogContent`.
 */
export function useShortcut(
    id: ShortcutId,
    handler: (event: KeyboardEvent) => void,
    { enabled = true }: { enabled?: boolean } = {},
): void {
    const layer = useContext(ShortcutLayerContext);
    const handlerRef = useRef(handler);

    useEffect(() => {
        handlerRef.current = handler;
    });

    useEffect(() => {
        if (!enabled) {
            return;
        }

        return shortcutRegistry.register({
            id,
            definition: SHORTCUTS[id],
            layer,
            handler: (event) => handlerRef.current(event),
        });
    }, [id, layer, enabled]);
}

export interface ShortcutHint {
    /** What the chip shows: `⌘⏎` on a Mac, `Ctrl ⏎` elsewhere. */
    keys: string;
    /** For the `aria-keyshortcuts` of the control the shortcut activates. */
    ariaKeyShortcuts: string;
}

/** Null until the platform is known, i.e. on the server and while hydrating. */
export function useShortcutHint(id: ShortcutId): ShortcutHint | null {
    const platform = usePlatform();

    if (!platform) {
        return null;
    }

    const combo = parseCombo(SHORTCUTS[id].keys);

    return {
        keys: formatCombo(combo, platform),
        ariaKeyShortcuts: toAriaKeyShortcuts(combo, platform),
    };
}
