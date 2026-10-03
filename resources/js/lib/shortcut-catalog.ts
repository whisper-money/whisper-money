import {
    comboSignature,
    formatCombo,
    isReservedCombo,
    parseCombo,
    type Platform,
} from '@/lib/key-combo';
import { __ } from '@/utils/i18n';

/**
 * Where a shortcut belongs. It groups the catalog (a cheat sheet lists one
 * scope per section) and tells which combos may share keys: two scopes never
 * live at once unless one of them is `global`, so `n` can mean one thing in a
 * dialog and another on a page.
 *
 * At runtime the layer stack decides what fires, not the scope — except for
 * `global`, whose shortcuts fire whatever dialog is open on top.
 */
export type ShortcutScope = 'global' | 'transaction-dialog';

export interface ShortcutDefinition {
    /** `mod+enter`, `shift+mod+k`, `n`… see `parseCombo`. */
    keys: string;
    scope: ShortcutScope;
    /** A function so it is translated when read, not when the module loads. */
    description: () => string;
    /**
     * Fire while focus is in a text field or a combobox. Off by default, or
     * typing an `n` into a description would trigger `n`; a combo with ⌘ or
     * Ctrl types nothing, so it can take it. Nothing fires from inside an open
     * listbox or menu either way.
     */
    allowInEditable?: boolean;
    /** Keep firing while the key is held down. Off for one-shot actions. */
    repeat?: boolean;
}

/**
 * Every keyboard shortcut in the app. The handler (`useShortcut`) and the chip
 * (`ShortcutKbd`) both read from here, so the hint never drifts from the key
 * that does it.
 */
export const SHORTCUTS = {
    'transaction-dialog.save': {
        keys: 'mod+enter',
        scope: 'transaction-dialog',
        description: () => __('Save the transaction'),
        allowInEditable: true,
    },
    'transaction-dialog.add-note': {
        keys: 'n',
        scope: 'transaction-dialog',
        description: () => __('Add note'),
    },
} satisfies Record<string, ShortcutDefinition>;

export type ShortcutId = keyof typeof SHORTCUTS;

const PLATFORMS: Platform[] = ['mac', 'other'];

function scopesOverlap(a: ShortcutScope, b: ShortcutScope): boolean {
    return a === b || a === 'global' || b === 'global';
}

/**
 * Pairs of definitions that would answer the same keys at the same time, on
 * either platform.
 */
export function findShortcutConflicts(
    definitions: Record<string, ShortcutDefinition>,
): string[] {
    const entries = Object.entries(definitions);
    const conflicts: string[] = [];

    entries.forEach(([id, definition], index) => {
        for (const [otherId, other] of entries.slice(index + 1)) {
            if (!scopesOverlap(definition.scope, other.scope)) {
                continue;
            }

            const clashOn = PLATFORMS.find(
                (platform) =>
                    comboSignature(parseCombo(definition.keys), platform) ===
                    comboSignature(parseCombo(other.keys), platform),
            );

            if (clashOn) {
                conflicts.push(
                    `${id} and ${otherId} both use ${formatCombo(parseCombo(definition.keys), clashOn)}`,
                );
            }
        }
    });

    return conflicts;
}

/** Definitions that take a combo the browser or the OS keeps for itself. */
export function findReservedShortcuts(
    definitions: Record<string, ShortcutDefinition>,
): string[] {
    return Object.entries(definitions).flatMap(([id, definition]) =>
        PLATFORMS.filter((platform) =>
            isReservedCombo(parseCombo(definition.keys), platform),
        ).map(
            (platform) =>
                `${id} takes ${formatCombo(parseCombo(definition.keys), platform)}, reserved on ${platform === 'mac' ? 'macOS' : 'Windows/Linux'}`,
        ),
    );
}
