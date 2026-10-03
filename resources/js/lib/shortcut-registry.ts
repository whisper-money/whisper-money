import {
    comboSignature,
    detectPlatform,
    formatCombo,
    matchesCombo,
    parseCombo,
    type KeyCombo,
    type Platform,
} from '@/lib/key-combo';
import { type ShortcutDefinition } from '@/lib/shortcut-catalog';

/**
 * The live side of the shortcut system: which shortcuts are mounted, on which
 * layer, and the one `keydown` listener that dispatches to them.
 *
 * Layers stack the way modals do: the page at the bottom (the root layer), a
 * dialog or drawer on top of it, a nested dialog on top of that. A key reaches
 * the topmost layer, then the ones beneath it until a modal layer stops it, so
 * the page's shortcuts sleep while a dialog is open. Shortcuts whose scope is
 * `global` are the exception and fire from any layer.
 *
 * The listener sits on `window` in the bubble phase, after every component's
 * own handlers: a field or a widget that owns a key (cmdk's Enter, Radix's
 * Escape) handles it first and, by preventing the default, keeps it.
 */

export const ROOT_LAYER = 'root';

export interface ShortcutRegistration {
    id: string;
    definition: ShortcutDefinition;
    layer: string;
    handler: (event: KeyboardEvent) => void;
}

interface Entry extends ShortcutRegistration {
    combo: KeyCombo;
}

interface ActiveLayer {
    id: string;
    parent: string | null;
    modal: boolean;
}

interface Options {
    platform?: () => Platform;
    /** Where the one listener goes; null leaves it to the caller (tests). */
    target?: Pick<Window, 'addEventListener'> | null;
    /** Dev-only report of two live shortcuts answering the same keys. */
    warn?: (message: string) => void;
}

/**
 * Text fields, and widgets that read plain keys themselves: a combobox, even
 * closed (a Radix Select trigger picks an option by typeahead), and an open
 * listbox or menu.
 */
const EDITABLE_SELECTOR = [
    'input:not([type=checkbox], [type=radio], [type=button], [type=submit], [type=reset], [type=range], [type=color], [type=file])',
    'textarea',
    'select',
    '[contenteditable]:not([contenteditable=false])',
    '[role=textbox]',
    '[role=searchbox]',
    '[role=spinbutton]',
    '[role=combobox]',
    '[role=listbox]',
    '[role=menu]',
    '[role=menubar]',
].join(', ');

export function isEditableTarget(target: EventTarget | null): boolean {
    return (
        target instanceof Element && target.closest(EDITABLE_SELECTOR) !== null
    );
}

/** IME composition: the keys belong to the candidate window, not to us. */
function isComposing(event: KeyboardEvent): boolean {
    return event.isComposing || event.keyCode === 229;
}

export function createShortcutRegistry({
    platform = detectPlatform,
    target = typeof window === 'undefined' ? null : window,
    warn = import.meta.env.DEV ? console.warn : () => {},
}: Options = {}) {
    /** Active layers, bottom to top. */
    let stack: ActiveLayer[] = [{ id: ROOT_LAYER, parent: null, modal: true }];
    let entries: Entry[] = [];
    let listening = false;

    function isDescendant(layer: ActiveLayer, ancestorId: string): boolean {
        let parent = layer.parent;

        while (parent !== null) {
            if (parent === ancestorId) {
                return true;
            }

            parent =
                stack.find((candidate) => candidate.id === parent)?.parent ??
                null;
        }

        return false;
    }

    /**
     * Puts the layer on top, which is where a dialog that just opened sits —
     * below any of its own descendants, though: when an outer and a nested
     * dialog open in the same render, React runs the inner one's effect first.
     */
    function activateLayer(
        id: string,
        { parent, modal }: { parent: string; modal: boolean },
    ): () => void {
        const layer: ActiveLayer = { id, parent, modal };
        const rest = stack.filter((candidate) => candidate.id !== id);
        const firstDescendant = rest.findIndex((candidate) =>
            isDescendant(candidate, id),
        );
        const at = firstDescendant === -1 ? rest.length : firstDescendant;

        stack = [...rest.slice(0, at), layer, ...rest.slice(at)];

        return () => {
            stack = stack.filter((candidate) => candidate !== layer);
        };
    }

    /** The layers a key reaches: from the top down, through the first modal one. */
    function reachableLayers(): string[] {
        const reachable: string[] = [];

        for (const layer of [...stack].reverse()) {
            reachable.push(layer.id);

            if (layer.modal) {
                break;
            }
        }

        return reachable;
    }

    function warnOnCollision(entry: Entry): void {
        const signature = comboSignature(entry.combo, platform());
        const collision = entries.find(
            (other) =>
                comboSignature(other.combo, platform()) === signature &&
                (other.layer === entry.layer ||
                    other.definition.scope === 'global' ||
                    entry.definition.scope === 'global'),
        );

        if (collision) {
            warn(
                `Keyboard shortcut "${entry.id}" (${formatCombo(entry.combo, platform())}) collides with "${collision.id}": both would answer it.`,
            );
        }
    }

    function register(registration: ShortcutRegistration): () => void {
        const entry: Entry = {
            ...registration,
            combo: parseCombo(registration.definition.keys),
        };

        warnOnCollision(entry);
        entries = [...entries, entry];

        if (target && !listening) {
            target.addEventListener('keydown', handleKeyDown);
            listening = true;
        }

        return () => {
            entries = entries.filter((candidate) => candidate !== entry);
        };
    }

    /** Topmost layer first, the most recent registration first within one. */
    function candidates(): Entry[] {
        const reachable = reachableLayers();
        const newestFirst = [...entries].reverse();

        return [
            ...reachable.flatMap((layer) =>
                newestFirst.filter((entry) => entry.layer === layer),
            ),
            ...newestFirst.filter(
                (entry) =>
                    entry.definition.scope === 'global' &&
                    !reachable.includes(entry.layer),
            ),
        ];
    }

    function handleKeyDown(event: KeyboardEvent): void {
        if (event.defaultPrevented || isComposing(event)) {
            return;
        }

        const editable = isEditableTarget(event.target);

        for (const entry of candidates()) {
            if (!matchesCombo(entry.combo, event, platform())) {
                continue;
            }

            if (editable && !entry.definition.allowInEditable) {
                continue;
            }

            event.preventDefault();

            // A held key repeats: a one-shot action keeps the first press only,
            // and the repeats still stop here, so a held ⌘⏎ never reaches the
            // form as a native Enter either.
            if (event.repeat && !entry.definition.repeat) {
                return;
            }

            entry.handler(event);

            return;
        }
    }

    return { activateLayer, register, handleKeyDown };
}

export type ShortcutRegistry = ReturnType<typeof createShortcutRegistry>;

export const shortcutRegistry = createShortcutRegistry();
