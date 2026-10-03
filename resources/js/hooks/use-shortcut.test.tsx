import { ShortcutLayer } from '@/components/shortcuts/shortcut-layer';
import { fireEvent, render, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useShortcut, useShortcutHint } from './use-shortcut';

function onPlatform(platform: string) {
    vi.spyOn(Navigator.prototype, 'platform', 'get').mockReturnValue(platform);
}

function pressN() {
    return fireEvent.keyDown(document.body, { key: 'n' });
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('useShortcut', () => {
    it('runs the handler of the latest render without registering again', () => {
        const first = vi.fn();
        const second = vi.fn();
        const { rerender } = renderHook(
            ({ handler }) =>
                useShortcut('transaction-dialog.add-note', handler),
            { initialProps: { handler: first } },
        );

        rerender({ handler: second });
        pressN();

        expect(first).not.toHaveBeenCalled();
        expect(second).toHaveBeenCalledOnce();
    });

    it('does nothing while disabled', () => {
        const handler = vi.fn();
        const { rerender } = renderHook(
            ({ enabled }) =>
                useShortcut('transaction-dialog.add-note', handler, {
                    enabled,
                }),
            { initialProps: { enabled: false } },
        );

        pressN();
        expect(handler).not.toHaveBeenCalled();

        rerender({ enabled: true });
        pressN();
        expect(handler).toHaveBeenCalledOnce();
    });

    it('stops when the component unmounts', () => {
        const handler = vi.fn();
        const { unmount } = renderHook(() =>
            useShortcut('transaction-dialog.add-note', handler),
        );

        unmount();
        pressN();

        expect(handler).not.toHaveBeenCalled();
    });

    it('registers on the layer around it, which puts the page to sleep', () => {
        const pageHandler = vi.fn();
        const layerHandler = vi.fn();

        function Page({ layerOpen }: { layerOpen: boolean }) {
            useShortcut('transaction-dialog.add-note', pageHandler);

            return (
                <ShortcutLayer active={layerOpen}>
                    <Inside />
                </ShortcutLayer>
            );
        }

        function Inside() {
            useShortcut('transaction-dialog.add-note', layerHandler);

            return null;
        }

        const { rerender } = render(<Page layerOpen />);

        pressN();
        expect(layerHandler).toHaveBeenCalledOnce();
        expect(pageHandler).not.toHaveBeenCalled();

        rerender(<Page layerOpen={false} />);
        pressN();
        expect(pageHandler).toHaveBeenCalledOnce();
        expect(layerHandler).toHaveBeenCalledOnce();
    });
});

describe('useShortcutHint', () => {
    it('spells the shortcut the Mac way on a Mac', () => {
        onPlatform('MacIntel');

        const { result } = renderHook(() =>
            useShortcutHint('transaction-dialog.save'),
        );

        expect(result.current).toEqual({
            keys: '⌘⏎',
            ariaKeyShortcuts: 'Meta+Enter',
        });
    });

    it('spells it with Ctrl everywhere else', () => {
        onPlatform('Win32');

        const { result } = renderHook(() =>
            useShortcutHint('transaction-dialog.save'),
        );

        expect(result.current).toEqual({
            keys: 'Ctrl ⏎',
            ariaKeyShortcuts: 'Control+Enter',
        });
    });
});
