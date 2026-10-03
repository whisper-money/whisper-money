import { afterEach, describe, expect, it, vi } from 'vitest';
import { type ShortcutDefinition } from './shortcut-catalog';
import {
    createShortcutRegistry,
    keyTargetKind,
    ROOT_LAYER,
} from './shortcut-registry';

function definition(
    keys: string,
    overrides: Partial<ShortcutDefinition> = {},
): ShortcutDefinition {
    return {
        keys,
        scope: 'transaction-dialog',
        description: () => keys,
        ...overrides,
    };
}

function makeRegistry() {
    const warn = vi.fn();
    const registry = createShortcutRegistry({
        platform: () => 'mac',
        target: null,
        warn,
    });

    return { registry, warn };
}

/** Dispatches on `target` so `event.target` is set, as it is in a browser. */
function press(
    registry: ReturnType<typeof createShortcutRegistry>,
    init: KeyboardEventInit,
    target: EventTarget = document.body,
): KeyboardEvent {
    const event = new KeyboardEvent('keydown', {
        bubbles: true,
        cancelable: true,
        ...init,
    });
    const listener = (e: Event) => registry.handleKeyDown(e as KeyboardEvent);

    target.addEventListener('keydown', listener);
    target.dispatchEvent(event);
    target.removeEventListener('keydown', listener);

    return event;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('dispatch', () => {
    it('runs the shortcut and keeps the key from the browser', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();

        registry.register({
            id: 'note',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler,
        });

        const event = press(registry, { key: 'n' });

        expect(handler).toHaveBeenCalledOnce();
        expect(event.defaultPrevented).toBe(true);
    });

    it('stops once unregistered', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();
        const unregister = registry.register({
            id: 'note',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler,
        });

        unregister();
        const event = press(registry, { key: 'n' });

        expect(handler).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('leaves a key some component already handled', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();

        registry.register({
            id: 'note',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler,
        });

        const event = new KeyboardEvent('keydown', {
            key: 'n',
            cancelable: true,
        });
        event.preventDefault();
        registry.handleKeyDown(event);

        expect(handler).not.toHaveBeenCalled();
    });

    it('ignores keys typed into an IME composition', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();

        registry.register({
            id: 'note',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler,
        });

        press(registry, { key: 'n', isComposing: true });
        // Some browsers flag composition only through the legacy keyCode.
        press(registry, { key: 'n', keyCode: 229 });

        expect(handler).not.toHaveBeenCalled();
    });

    it('fires a one-shot action once while the key is held', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();

        registry.register({
            id: 'save',
            definition: definition('mod+enter', { allowInEditable: true }),
            layer: ROOT_LAYER,
            handler,
        });

        press(registry, { key: 'Enter', metaKey: true });
        const repeat = press(registry, {
            key: 'Enter',
            metaKey: true,
            repeat: true,
        });

        expect(handler).toHaveBeenCalledOnce();
        // Swallowed all the same, so it never reaches the form as an Enter.
        expect(repeat.defaultPrevented).toBe(true);
    });

    it('keeps firing while held when the shortcut asks for it', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();

        registry.register({
            id: 'down',
            definition: definition('j', { repeat: true }),
            layer: ROOT_LAYER,
            handler,
        });

        press(registry, { key: 'j' });
        press(registry, { key: 'j', repeat: true });

        expect(handler).toHaveBeenCalledTimes(2);
    });
});

describe('the editable guard', () => {
    it('keeps a plain key out of text fields', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();
        const input = document.body.appendChild(
            document.createElement('input'),
        );

        registry.register({
            id: 'note',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler,
        });

        const event = press(registry, { key: 'n' }, input);

        expect(handler).not.toHaveBeenCalled();
        // The n is typed, as it should be.
        expect(event.defaultPrevented).toBe(false);
    });

    it('lets a shortcut that opts in fire from a field', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();
        const textarea = document.body.appendChild(
            document.createElement('textarea'),
        );

        registry.register({
            id: 'save',
            definition: definition('mod+enter', { allowInEditable: true }),
            layer: ROOT_LAYER,
            handler,
        });

        press(registry, { key: 'Enter', metaKey: true }, textarea);

        expect(handler).toHaveBeenCalledOnce();
    });

    it('fires nothing from inside an open listbox, not even a combo that opts in', () => {
        const { registry } = makeRegistry();
        const handler = vi.fn();
        document.body.innerHTML =
            '<div role="listbox"><div role="option" tabindex="-1"></div></div>';

        registry.register({
            id: 'save',
            definition: definition('mod+enter', { allowInEditable: true }),
            layer: ROOT_LAYER,
            handler,
        });

        const event = press(
            registry,
            { key: 'Enter', metaKey: true },
            document.querySelector('[role=option]')!,
        );

        expect(handler).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it.each([
        ['<input type="text">', 'text'],
        ['<input type="checkbox">', null],
        ['<textarea></textarea>', 'text'],
        ['<select></select>', 'text'],
        ['<div contenteditable="true"></div>', 'text'],
        ['<div contenteditable="false"></div>', null],
        ['<button role="combobox"></button>', 'text'],
        ['<div role="listbox"><div role="option"></div></div>', 'popup'],
        ['<div role="menu"><div role="menuitem"></div></div>', 'popup'],
        ['<button></button>', null],
    ])('reads %s as %s', (html, kind) => {
        document.body.innerHTML = html;
        const element = document.body.firstElementChild!;
        const target = element.firstElementChild ?? element;

        expect(keyTargetKind(target)).toBe(kind);
    });
});

describe('layers', () => {
    it('puts the page to sleep while a modal layer is open', () => {
        const { registry } = makeRegistry();
        const pageHandler = vi.fn();
        const dialogHandler = vi.fn();

        registry.register({
            id: 'page.next',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler: pageHandler,
        });
        const close = registry.activateLayer('dialog', {
            parent: ROOT_LAYER,
            modal: true,
        });
        registry.register({
            id: 'dialog.note',
            definition: definition('n'),
            layer: 'dialog',
            handler: dialogHandler,
        });

        press(registry, { key: 'n' });
        expect(dialogHandler).toHaveBeenCalledOnce();
        expect(pageHandler).not.toHaveBeenCalled();

        close();
        press(registry, { key: 'n' });
        expect(pageHandler).toHaveBeenCalledOnce();
    });

    it('blocks the page even for keys the dialog does not use', () => {
        const { registry } = makeRegistry();
        const pageHandler = vi.fn();

        registry.register({
            id: 'page.next',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler: pageHandler,
        });
        registry.activateLayer('dialog', { parent: ROOT_LAYER, modal: true });

        const event = press(registry, { key: 'n' });

        expect(pageHandler).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('lets keys through a non-modal layer to the one beneath', () => {
        const { registry } = makeRegistry();
        const pageHandler = vi.fn();

        registry.register({
            id: 'page.next',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler: pageHandler,
        });
        registry.activateLayer('panel', { parent: ROOT_LAYER, modal: false });

        press(registry, { key: 'n' });

        expect(pageHandler).toHaveBeenCalledOnce();
    });

    it('stops a dialog’s shortcuts while a nested one is on top', () => {
        const { registry } = makeRegistry();
        const outerHandler = vi.fn();

        registry.activateLayer('outer', { parent: ROOT_LAYER, modal: true });
        registry.register({
            id: 'outer.save',
            definition: definition('mod+enter'),
            layer: 'outer',
            handler: outerHandler,
        });
        const closeInner = registry.activateLayer('inner', {
            parent: 'outer',
            modal: true,
        });

        press(registry, { key: 'Enter', metaKey: true });
        expect(outerHandler).not.toHaveBeenCalled();

        closeInner();
        press(registry, { key: 'Enter', metaKey: true });
        expect(outerHandler).toHaveBeenCalledOnce();
    });

    it('keeps a nested layer on top when it is activated before its parent', () => {
        const { registry } = makeRegistry();
        const outerHandler = vi.fn();
        const innerHandler = vi.fn();

        // React runs a child's effects before its parent's, so a nested dialog
        // open on mount activates first.
        registry.activateLayer('inner', { parent: 'outer', modal: true });
        registry.activateLayer('outer', { parent: ROOT_LAYER, modal: true });
        registry.register({
            id: 'outer.note',
            definition: definition('n'),
            layer: 'outer',
            handler: outerHandler,
        });
        registry.register({
            id: 'inner.note',
            definition: definition('n'),
            layer: 'inner',
            handler: innerHandler,
        });

        press(registry, { key: 'n' });

        expect(innerHandler).toHaveBeenCalledOnce();
        expect(outerHandler).not.toHaveBeenCalled();
    });

    it('puts a sibling dialog opened later on top', () => {
        const { registry } = makeRegistry();
        const firstHandler = vi.fn();
        const secondHandler = vi.fn();

        registry.activateLayer('first', { parent: ROOT_LAYER, modal: true });
        registry.activateLayer('second', { parent: ROOT_LAYER, modal: true });
        registry.register({
            id: 'first.note',
            definition: definition('n'),
            layer: 'first',
            handler: firstHandler,
        });
        registry.register({
            id: 'second.note',
            definition: definition('n'),
            layer: 'second',
            handler: secondHandler,
        });

        press(registry, { key: 'n' });

        expect(secondHandler).toHaveBeenCalledOnce();
        expect(firstHandler).not.toHaveBeenCalled();
    });

    it('fires a global shortcut whatever is open on top', () => {
        const { registry } = makeRegistry();
        const searchHandler = vi.fn();

        registry.register({
            id: 'search',
            definition: definition('mod+k', { scope: 'global' }),
            layer: ROOT_LAYER,
            handler: searchHandler,
        });
        registry.activateLayer('dialog', { parent: ROOT_LAYER, modal: true });

        press(registry, { key: 'k', metaKey: true });

        expect(searchHandler).toHaveBeenCalledOnce();
    });
});

describe('collisions', () => {
    it('warns when two live shortcuts on one layer answer the same keys', () => {
        const { registry, warn } = makeRegistry();

        registry.register({
            id: 'first',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler: vi.fn(),
        });
        registry.register({
            id: 'second',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler: vi.fn(),
        });

        expect(warn).toHaveBeenCalledWith(
            expect.stringContaining('"second" (N) collides with "first"'),
        );
    });

    it('warns when a global shortcut shadows one on any layer', () => {
        const { registry, warn } = makeRegistry();

        registry.register({
            id: 'search',
            definition: definition('mod+k', { scope: 'global' }),
            layer: ROOT_LAYER,
            handler: vi.fn(),
        });
        registry.register({
            id: 'link',
            definition: definition('mod+k'),
            layer: 'dialog',
            handler: vi.fn(),
        });

        expect(warn).toHaveBeenCalledOnce();
    });

    it('says nothing about the same keys on two different layers', () => {
        const { registry, warn } = makeRegistry();

        registry.register({
            id: 'page.next',
            definition: definition('n'),
            layer: ROOT_LAYER,
            handler: vi.fn(),
        });
        registry.register({
            id: 'dialog.note',
            definition: definition('n'),
            layer: 'dialog',
            handler: vi.fn(),
        });

        expect(warn).not.toHaveBeenCalled();
    });
});

describe('the listener', () => {
    it('adds a single keydown listener however many shortcuts register', () => {
        const target = { addEventListener: vi.fn() };
        const registry = createShortcutRegistry({
            platform: () => 'mac',
            target,
            warn: vi.fn(),
        });

        registry.register({
            id: 'a',
            definition: definition('a'),
            layer: ROOT_LAYER,
            handler: vi.fn(),
        });
        registry.register({
            id: 'b',
            definition: definition('b'),
            layer: ROOT_LAYER,
            handler: vi.fn(),
        });

        expect(target.addEventListener).toHaveBeenCalledOnce();
        expect(target.addEventListener).toHaveBeenCalledWith(
            'keydown',
            registry.handleKeyDown,
        );
    });
});
