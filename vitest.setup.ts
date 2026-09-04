import '@testing-library/jest-dom/vitest';
import { beforeEach } from 'vitest';

const store = new Map<string, string>();

/**
 * Guarded because the suite also runs files under the `node` environment, where
 * there is no `window` to patch - that is the whole point of those files, which
 * render server-side and would otherwise never notice a page reaching for the
 * DOM during render.
 */
if (typeof window !== 'undefined') {
    /**
     * jsdom exposes `window.localStorage` as a getter that hands back undefined, so
     * every test sees what a browser with site data blocked sees. Give the suite a
     * working in-memory Storage, emptied between tests; the tests that care about a
     * hostile localStorage still replace it themselves (see lib/safe-storage.test.ts).
     */
    Object.defineProperty(window, 'localStorage', {
        configurable: true,
        value: {
            getItem: (key: string) => store.get(key) ?? null,
            setItem: (key: string, value: string) => void store.set(key, value),
            removeItem: (key: string) => void store.delete(key),
            clear: () => store.clear(),
        },
    });

    /**
     * jsdom ships no `PointerEvent`, so `fireEvent.pointerDown` falls back to a plain
     * `Event` and Radix triggers (dropdowns, selects) never open. `MouseEvent` already
     * carries the `button` / `ctrlKey` fields they check.
     */
    if (!('PointerEvent' in window)) {
        window.PointerEvent = MouseEvent as unknown as typeof window.PointerEvent;
    }
}

beforeEach(() => {
    store.clear();
});
