/**
 * Key combos written once, platform-agnostic — `mod+enter`, `shift+mod+k`, `n`,
 * `?` — then matched against a KeyboardEvent and formatted for the platform.
 *
 * `mod` is the platform's command key: ⌘ on Apple devices, Ctrl everywhere
 * else. Only that key counts: Ctrl on a Mac or ⌘ (the Windows key) on a PC does
 * not stand in for it, so a combo never lands on what the other OS keeps for
 * itself.
 *
 * Keys are matched on `event.key`, which follows the user's layout: the key
 * labelled A on an AZERTY keyboard is A. When `event.key` is not a Latin
 * character — a Cyrillic, Greek or Hebrew layout, or the dead key ⌥ produces on
 * a Mac — the physical key in `event.code` is used instead, so `n` still works
 * on a Russian keyboard, on the key where N sits on a US one.
 */

export type Platform = 'mac' | 'other';

export interface KeyCombo {
    /** Lowercase letter or digit, a punctuation character, or a named key (`enter`, `up`…). */
    key: string;
    mod: boolean;
    ctrl: boolean;
    alt: boolean;
    shift: boolean;
    meta: boolean;
}

type Modifier = 'mod' | 'ctrl' | 'alt' | 'shift' | 'meta';

const MODIFIER_ALIASES = new Map<string, Modifier>([
    ['mod', 'mod'],
    ['ctrl', 'ctrl'],
    ['control', 'ctrl'],
    ['alt', 'alt'],
    ['option', 'alt'],
    ['shift', 'shift'],
    ['meta', 'meta'],
    ['cmd', 'meta'],
    ['command', 'meta'],
]);

/** Named keys, by the name a combo uses, with the `event.key` that reports them. */
const NAMED_KEYS = new Map(
    Object.entries({
        enter: 'Enter',
        escape: 'Escape',
        space: ' ',
        tab: 'Tab',
        backspace: 'Backspace',
        delete: 'Delete',
        up: 'ArrowUp',
        down: 'ArrowDown',
        left: 'ArrowLeft',
        right: 'ArrowRight',
        home: 'Home',
        end: 'End',
        pageup: 'PageUp',
        pagedown: 'PageDown',
        // `+` separates the parts of a combo, so the key itself goes by name.
        plus: '+',
    }),
);

const KEY_ALIASES = new Map([
    ['return', 'enter'],
    ['esc', 'escape'],
]);

const COMBO_NAME_BY_EVENT_KEY = new Map(
    [...NAMED_KEYS].map(([name, eventKey]) => [eventKey, name]),
);

const MAC_KEY_LABELS: Record<string, string> = {
    enter: '⏎',
    escape: 'Esc',
    space: 'Space',
    tab: '⇥',
    backspace: '⌫',
    delete: '⌦',
    up: '↑',
    down: '↓',
    left: '←',
    right: '→',
    home: '↖',
    end: '↘',
    pageup: '⇞',
    pagedown: '⇟',
    plus: '+',
};

const OTHER_KEY_LABELS: Record<string, string> = {
    ...MAC_KEY_LABELS,
    tab: 'Tab',
    backspace: 'Backspace',
    delete: 'Del',
    home: 'Home',
    end: 'End',
    pageup: 'PgUp',
    pagedown: 'PgDn',
};

const LETTER_OR_DIGIT = /^[a-z0-9]$/;
const PRINTABLE_ASCII = /^[\x21-\x7e]$/;

/**
 * Parses `shift+mod+k`-style notation. Throws on anything it cannot read, so a
 * typo in the catalog fails its test instead of a shortcut that never fires.
 */
export function parseCombo(notation: string): KeyCombo {
    const combo: KeyCombo = {
        key: '',
        mod: false,
        ctrl: false,
        alt: false,
        shift: false,
        meta: false,
    };

    for (const part of notation.toLowerCase().split('+')) {
        const token = part.trim();
        const modifier = MODIFIER_ALIASES.get(token);

        if (modifier) {
            combo[modifier] = true;
            continue;
        }

        if (combo.key !== '') {
            throw new Error(`"${notation}" names more than one key.`);
        }

        combo.key = parseKey(token, notation);
    }

    if (combo.key === '') {
        throw new Error(`"${notation}" names no key.`);
    }

    return combo;
}

function parseKey(token: string, notation: string): string {
    const name = KEY_ALIASES.get(token) ?? token;

    if (NAMED_KEYS.has(name) || PRINTABLE_ASCII.test(name)) {
        return name;
    }

    throw new Error(`"${notation}" has a key we do not know: "${token}".`);
}

/** The modifiers a combo needs on this platform, `mod` turned into ⌘ or Ctrl. */
function resolveModifiers(
    combo: KeyCombo,
    platform: Platform,
): Pick<KeyCombo, 'ctrl' | 'alt' | 'shift' | 'meta'> {
    return {
        ctrl: combo.ctrl || (combo.mod && platform === 'other'),
        meta: combo.meta || (combo.mod && platform === 'mac'),
        alt: combo.alt,
        shift: combo.shift,
    };
}

/**
 * Shift is part of the character for punctuation: `?` is Shift+/ on a US
 * keyboard and its own key on others. So it is only compared for letters,
 * digits and named keys, and a combo names the character typed (`?`), never
 * the keys that type it.
 */
function isShiftSensitive(key: string): boolean {
    return LETTER_OR_DIGIT.test(key) || NAMED_KEYS.has(key);
}

/** The key an event reports, in the notation a combo uses. */
function keyFromEvent(event: KeyboardEvent): string {
    const named = COMBO_NAME_BY_EVENT_KEY.get(event.key);

    if (named) {
        return named;
    }

    const key = event.key.toLowerCase();

    if (key.length === 1 && PRINTABLE_ASCII.test(key)) {
        return key;
    }

    return keyFromCode(event.code) ?? key;
}

/** `KeyN` → `n`, `Digit4` → `4`: the physical key, whatever the layout types. */
function keyFromCode(code: string): string | null {
    const match = /^(?:Key([A-Z])|Digit([0-9]))$/.exec(code);

    if (!match) {
        return null;
    }

    return (match[1] ?? match[2]).toLowerCase();
}

export function matchesCombo(
    combo: KeyCombo,
    event: KeyboardEvent,
    platform: Platform,
): boolean {
    const expected = resolveModifiers(combo, platform);

    if (
        event.ctrlKey !== expected.ctrl ||
        event.metaKey !== expected.meta ||
        event.altKey !== expected.alt
    ) {
        return false;
    }

    if (isShiftSensitive(combo.key) && event.shiftKey !== expected.shift) {
        return false;
    }

    return keyFromEvent(event) === combo.key;
}

/**
 * What the combo resolves to on this platform, as one string: two combos
 * collide on a platform exactly when their signatures match there.
 */
export function comboSignature(combo: KeyCombo, platform: Platform): string {
    const modifiers = resolveModifiers(combo, platform);

    return [
        modifiers.ctrl && 'ctrl',
        modifiers.alt && 'alt',
        modifiers.shift && isShiftSensitive(combo.key) && 'shift',
        modifiers.meta && 'meta',
        combo.key,
    ]
        .filter(Boolean)
        .join('+');
}

function keyLabel(key: string, platform: Platform): string {
    const labels = platform === 'mac' ? MAC_KEY_LABELS : OTHER_KEY_LABELS;

    return labels[key] ?? key.toUpperCase();
}

/**
 * The combo as the chip shows it: Mac glyphs run together in Apple's order
 * (`⇧⌘K`, `⌘⏎`), other platforms spell the modifiers out with a space between
 * (`Ctrl Shift K`, `Ctrl ⏎`).
 */
export function formatCombo(combo: KeyCombo, platform: Platform): string {
    const modifiers = resolveModifiers(combo, platform);
    const key = keyLabel(combo.key, platform);

    if (platform === 'mac') {
        return [
            modifiers.ctrl && '⌃',
            modifiers.alt && '⌥',
            modifiers.shift && '⇧',
            modifiers.meta && '⌘',
            key,
        ]
            .filter(Boolean)
            .join('');
    }

    return [
        modifiers.ctrl && 'Ctrl',
        modifiers.alt && 'Alt',
        modifiers.shift && 'Shift',
        modifiers.meta && 'Meta',
        key,
    ]
        .filter(Boolean)
        .join(' ');
}

/** The value for `aria-keyshortcuts`: `Meta+Enter`, `Control+Enter`, `N`. */
export function toAriaKeyShortcuts(
    combo: KeyCombo,
    platform: Platform,
): string {
    const modifiers = resolveModifiers(combo, platform);
    const key =
        combo.key === 'space'
            ? 'Space'
            : (NAMED_KEYS.get(combo.key) ?? combo.key.toUpperCase());

    return [
        modifiers.ctrl && 'Control',
        modifiers.alt && 'Alt',
        modifiers.shift && 'Shift',
        modifiers.meta && 'Meta',
        key,
    ]
        .filter(Boolean)
        .join('+');
}

interface NavigatorLike {
    platform?: string;
    userAgent?: string;
    userAgentData?: { platform?: string };
}

/**
 * iPadOS reports itself as `MacIntel`, which is what we want: its keyboards
 * have a ⌘ key too. Client-only — the server cannot know, see `usePlatform`.
 */
export function detectPlatform(
    navigator: NavigatorLike | undefined = globalThis.navigator,
): Platform {
    const platform =
        navigator?.userAgentData?.platform ||
        navigator?.platform ||
        navigator?.userAgent ||
        '';

    return /mac|iphone|ipad|ipod/i.test(platform) ? 'mac' : 'other';
}

/**
 * Combos the browser or the OS keeps for itself. Some never reach the page at
 * all (⌘Q, Ctrl+N, Ctrl+T, Ctrl+W in Chrome); the rest the page could take but
 * would break a reflex everybody has (reload, address bar, print, find, copy).
 */
const RESERVED_COMBOS = [
    'mod+n',
    'mod+t',
    'mod+w',
    'mod+r',
    'mod+l',
    'mod+q',
    'mod+p',
    'mod+f',
    'mod+d',
    'mod+h',
    'mod+m',
    'mod+j',
    'mod+o',
    'mod+a',
    'mod+c',
    'mod+v',
    'mod+x',
    'mod+z',
    'mod+y',
    'shift+mod+z',
    'shift+mod+n',
    'shift+mod+t',
    'shift+mod+w',
    'shift+mod+r',
    'shift+mod+delete',
    'mod+tab',
    'shift+mod+tab',
    'ctrl+tab',
    'shift+ctrl+tab',
    'mod+[',
    'mod+]',
    'alt+left',
    'alt+right',
    ...'0123456789'.split('').map((digit) => `mod+${digit}`),
].map((notation) => parseCombo(notation));

export function isReservedCombo(combo: KeyCombo, platform: Platform): boolean {
    const signature = comboSignature(combo, platform);

    return RESERVED_COMBOS.some(
        (reserved) => comboSignature(reserved, platform) === signature,
    );
}
