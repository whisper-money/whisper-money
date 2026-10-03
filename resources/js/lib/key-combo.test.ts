import { describe, expect, it } from 'vitest';
import {
    comboSignature,
    detectPlatform,
    formatCombo,
    isReservedCombo,
    matchesCombo,
    parseCombo,
    toAriaKeyShortcuts,
} from './key-combo';

function keydown(init: KeyboardEventInit): KeyboardEvent {
    return new KeyboardEvent('keydown', init);
}

describe('parseCombo', () => {
    it('reads modifiers and the key in any order and case', () => {
        expect(parseCombo('Shift+Mod+K')).toEqual({
            key: 'k',
            mod: true,
            ctrl: false,
            alt: false,
            shift: true,
            meta: false,
        });
        expect(parseCombo('enter+mod')).toMatchObject({
            key: 'enter',
            mod: true,
        });
    });

    it('takes the usual spellings of the modifiers and named keys', () => {
        expect(parseCombo('cmd+option+return')).toMatchObject({
            key: 'enter',
            meta: true,
            alt: true,
        });
        expect(parseCombo('control+esc')).toMatchObject({
            key: 'escape',
            ctrl: true,
        });
    });

    it('reads punctuation as the character itself', () => {
        expect(parseCombo('?').key).toBe('?');
        expect(parseCombo('mod+plus').key).toBe('plus');
    });

    it.each(['mod', 'mod+', 'a+b', 'mod+enterr', 'hyper+k', 'constructor'])(
        'refuses "%s"',
        (notation) => {
            expect(() => parseCombo(notation)).toThrow();
        },
    );
});

describe('matchesCombo', () => {
    const save = parseCombo('mod+enter');

    it('takes ⌘ for mod on a Mac and Ctrl everywhere else', () => {
        expect(
            matchesCombo(save, keydown({ key: 'Enter', metaKey: true }), 'mac'),
        ).toBe(true);
        expect(
            matchesCombo(
                save,
                keydown({ key: 'Enter', ctrlKey: true }),
                'other',
            ),
        ).toBe(true);
    });

    it('does not let the other platform’s key stand in for mod', () => {
        expect(
            matchesCombo(save, keydown({ key: 'Enter', ctrlKey: true }), 'mac'),
        ).toBe(false);
        expect(
            matchesCombo(
                save,
                keydown({ key: 'Enter', metaKey: true }),
                'other',
            ),
        ).toBe(false);
    });

    it('needs the modifiers exactly, extra ones included', () => {
        expect(matchesCombo(save, keydown({ key: 'Enter' }), 'mac')).toBe(
            false,
        );
        expect(
            matchesCombo(
                save,
                keydown({ key: 'Enter', metaKey: true, shiftKey: true }),
                'mac',
            ),
        ).toBe(false);
        expect(
            matchesCombo(
                parseCombo('n'),
                keydown({ key: 'n', altKey: true }),
                'other',
            ),
        ).toBe(false);
    });

    it('matches letters whatever the case', () => {
        const note = parseCombo('n');

        expect(matchesCombo(note, keydown({ key: 'n' }), 'mac')).toBe(true);
        // Caps Lock: an uppercase key without Shift.
        expect(matchesCombo(note, keydown({ key: 'N' }), 'mac')).toBe(true);
        // Shift is still a modifier for a letter.
        expect(
            matchesCombo(note, keydown({ key: 'N', shiftKey: true }), 'mac'),
        ).toBe(false);
    });

    it('follows the layout for Latin letters', () => {
        // AZERTY: the key labelled A sits where Q is on a US keyboard.
        expect(
            matchesCombo(
                parseCombo('a'),
                keydown({ key: 'a', code: 'KeyQ' }),
                'other',
            ),
        ).toBe(true);
    });

    it('falls back to the physical key on a non-Latin layout', () => {
        // Russian: the key where N is on a US keyboard types т.
        expect(
            matchesCombo(
                parseCombo('n'),
                keydown({ key: 'т', code: 'KeyN' }),
                'other',
            ),
        ).toBe(true);
    });

    it('falls back to the physical key for ⌥ dead keys on a Mac', () => {
        expect(
            matchesCombo(
                parseCombo('alt+n'),
                keydown({ key: 'Dead', code: 'KeyN', altKey: true }),
                'mac',
            ),
        ).toBe(true);
    });

    it('ignores Shift for punctuation, which it takes to type', () => {
        const help = parseCombo('?');

        expect(
            matchesCombo(help, keydown({ key: '?', shiftKey: true }), 'other'),
        ).toBe(true);
        expect(matchesCombo(help, keydown({ key: '?' }), 'other')).toBe(true);
    });
});

describe('formatCombo', () => {
    it('runs Mac glyphs together in Apple’s order', () => {
        expect(formatCombo(parseCombo('mod+enter'), 'mac')).toBe('⌘⏎');
        expect(formatCombo(parseCombo('mod+shift+k'), 'mac')).toBe('⇧⌘K');
        expect(formatCombo(parseCombo('ctrl+alt+shift+mod+k'), 'mac')).toBe(
            '⌃⌥⇧⌘K',
        );
    });

    it('spells modifiers out elsewhere, a space between', () => {
        expect(formatCombo(parseCombo('mod+enter'), 'other')).toBe('Ctrl ⏎');
        expect(formatCombo(parseCombo('mod+shift+k'), 'other')).toBe(
            'Ctrl Shift K',
        );
        expect(formatCombo(parseCombo('alt+up'), 'other')).toBe('Alt ↑');
    });

    it('shows a plain key alone', () => {
        expect(formatCombo(parseCombo('n'), 'mac')).toBe('N');
        expect(formatCombo(parseCombo('n'), 'other')).toBe('N');
        expect(formatCombo(parseCombo('?'), 'other')).toBe('?');
    });
});

describe('toAriaKeyShortcuts', () => {
    it('names the keys the way aria-keyshortcuts expects', () => {
        expect(toAriaKeyShortcuts(parseCombo('mod+enter'), 'mac')).toBe(
            'Meta+Enter',
        );
        expect(toAriaKeyShortcuts(parseCombo('mod+enter'), 'other')).toBe(
            'Control+Enter',
        );
        expect(toAriaKeyShortcuts(parseCombo('n'), 'mac')).toBe('N');
        expect(toAriaKeyShortcuts(parseCombo('shift+space'), 'mac')).toBe(
            'Shift+Space',
        );
    });
});

describe('comboSignature', () => {
    it('is the same for two spellings of the same keys on a platform', () => {
        expect(comboSignature(parseCombo('mod+enter'), 'mac')).toBe(
            comboSignature(parseCombo('cmd+return'), 'mac'),
        );
        expect(comboSignature(parseCombo('mod+enter'), 'other')).not.toBe(
            comboSignature(parseCombo('cmd+return'), 'other'),
        );
    });
});

describe('isReservedCombo', () => {
    it.each(['mod+n', 'mod+t', 'mod+w', 'mod+r', 'mod+l', 'mod+q', 'mod+p'])(
        'reserves %s on both platforms',
        (notation) => {
            expect(isReservedCombo(parseCombo(notation), 'mac')).toBe(true);
            expect(isReservedCombo(parseCombo(notation), 'other')).toBe(true);
        },
    );

    it('reserves Ctrl+R on Windows but not on a Mac, where it is free', () => {
        expect(isReservedCombo(parseCombo('ctrl+r'), 'other')).toBe(true);
        expect(isReservedCombo(parseCombo('ctrl+r'), 'mac')).toBe(false);
    });

    it('leaves the combos apps commonly take alone', () => {
        expect(isReservedCombo(parseCombo('mod+enter'), 'mac')).toBe(false);
        expect(isReservedCombo(parseCombo('mod+k'), 'other')).toBe(false);
        expect(isReservedCombo(parseCombo('n'), 'other')).toBe(false);
    });
});

describe('detectPlatform', () => {
    it.each([
        [{ platform: 'MacIntel' }, 'mac'],
        [{ userAgentData: { platform: 'macOS' } }, 'mac'],
        [{ platform: 'iPhone' }, 'mac'],
        [{ platform: 'Win32' }, 'other'],
        [{ platform: 'Linux x86_64' }, 'other'],
        [{ userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0)' }, 'mac'],
    ] as const)('reads %j as %s', (navigator, platform) => {
        expect(detectPlatform(navigator)).toBe(platform);
    });
});
