import { afterEach, describe, expect, it } from 'vitest';
import { readStoredValue, writeStoredValue } from './safe-storage';

const realStorage = Object.getOwnPropertyDescriptor(window, 'localStorage');

const replaceStorage = (value: unknown) => {
    Object.defineProperty(window, 'localStorage', {
        configurable: true,
        get: () => value,
    });
};

afterEach(() => {
    if (realStorage) {
        Object.defineProperty(window, 'localStorage', realStorage);

        return;
    }

    // jsdom exposes localStorage on the prototype, so there is no own
    // descriptor to put back — drop the override instead.
    delete (window as { localStorage?: unknown }).localStorage;
});

describe('safe storage', () => {
    it('reads and writes through a working localStorage', () => {
        const stored = new Map<string, string>();
        replaceStorage({
            getItem: (key: string) => stored.get(key) ?? null,
            setItem: (key: string, value: string) => stored.set(key, value),
        });

        writeStoredValue('appearance', 'dark');

        expect(readStoredValue('appearance')).toBe('dark');
        expect(readStoredValue('never-set')).toBeNull();
    });

    // Android WebViews with DOM storage disabled expose the global as null,
    // which is what crashed initializeTheme in PHP-LARAVEL-57.
    it('survives a null localStorage', () => {
        replaceStorage(null);

        expect(readStoredValue('appearance')).toBeNull();
        expect(() => writeStoredValue('appearance', 'dark')).not.toThrow();
    });

    // Blocking cookies/site data makes the first access throw SecurityError —
    // PHP-LARAVEL-4Y, same boot frame.
    it('survives a localStorage that throws on access', () => {
        Object.defineProperty(window, 'localStorage', {
            configurable: true,
            get: () => {
                throw new DOMException('The operation is insecure.');
            },
        });

        expect(readStoredValue('appearance')).toBeNull();
        expect(() => writeStoredValue('appearance', 'dark')).not.toThrow();
    });

    it('survives a storage whose setItem throws, e.g. an exhausted quota', () => {
        replaceStorage({
            getItem: () => null,
            setItem: () => {
                throw new DOMException('QuotaExceededError');
            },
        });

        expect(() => writeStoredValue('appearance', 'dark')).not.toThrow();
    });
});
