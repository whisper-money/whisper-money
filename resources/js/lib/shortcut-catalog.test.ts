import { describe, expect, it } from 'vitest';
import { parseCombo } from './key-combo';
import {
    findReservedShortcuts,
    findShortcutConflicts,
    SHORTCUTS,
    type ShortcutDefinition,
} from './shortcut-catalog';

function definition(
    keys: string,
    scope: ShortcutDefinition['scope'],
): ShortcutDefinition {
    return { keys, scope, description: () => keys };
}

describe('the shortcut catalog', () => {
    it('writes every combo in notation the parser reads', () => {
        for (const { keys } of Object.values(SHORTCUTS)) {
            expect(() => parseCombo(keys)).not.toThrow();
        }
    });

    it('gives every shortcut a description for the cheat sheet', () => {
        for (const { description } of Object.values(SHORTCUTS)) {
            expect(description()).not.toBe('');
        }
    });

    it('never gives two shortcuts the same keys where both can fire', () => {
        expect(findShortcutConflicts(SHORTCUTS)).toEqual([]);
    });

    it('stays off the combos the browser and the OS keep for themselves', () => {
        expect(findReservedShortcuts(SHORTCUTS)).toEqual([]);
    });
});

describe('findShortcutConflicts', () => {
    it('catches the same keys twice in one scope', () => {
        expect(
            findShortcutConflicts({
                save: definition('mod+enter', 'transaction-dialog'),
                saveAgain: definition('mod+enter', 'transaction-dialog'),
            }),
        ).toEqual(['save and saveAgain both use ⌘⏎']);
    });

    it('catches a global shortcut shadowing a scoped one', () => {
        expect(
            findShortcutConflicts({
                search: definition('mod+k', 'global'),
                link: definition('mod+k', 'transaction-dialog'),
            }),
        ).toHaveLength(1);
    });

    it('catches two spellings of the same keys on one platform', () => {
        expect(
            findShortcutConflicts({
                save: definition('mod+enter', 'transaction-dialog'),
                macSave: definition('cmd+return', 'transaction-dialog'),
            }),
        ).toEqual(['save and macSave both use ⌘⏎']);
    });

    it('lets two scopes that never live together share keys', () => {
        expect(
            findShortcutConflicts({
                note: definition('n', 'transaction-dialog'),
                // A scope the catalog does not have yet, standing in for a page.
                next: definition(
                    'n',
                    'categorize-page' as ShortcutDefinition['scope'],
                ),
            }),
        ).toEqual([]);
    });

    it('tells keys apart by their modifiers', () => {
        expect(
            findShortcutConflicts({
                note: definition('n', 'transaction-dialog'),
                other: definition('shift+n', 'transaction-dialog'),
            }),
        ).toEqual([]);
    });
});

describe('findReservedShortcuts', () => {
    it('catches the categorize page’s Ctrl+R and Ctrl+N on Windows', () => {
        expect(
            findReservedShortcuts({
                rules: definition('ctrl+r', 'global'),
                skip: definition('ctrl+n', 'global'),
            }),
        ).toEqual([
            'rules takes Ctrl R, reserved on Windows/Linux',
            'skip takes Ctrl N, reserved on Windows/Linux',
        ]);
    });

    it('catches mod+N on both platforms', () => {
        expect(
            findReservedShortcuts({ create: definition('mod+n', 'global') }),
        ).toEqual([
            'create takes ⌘N, reserved on macOS',
            'create takes Ctrl N, reserved on Windows/Linux',
        ]);
    });
});
