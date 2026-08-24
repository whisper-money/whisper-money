import { initializeTheme } from '@/hooks/use-appearance';
import { initializeChartColorScheme } from '@/hooks/use-chart-color-scheme';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { readStoredValue, writeStoredValue } from './safe-storage';

const realStorage = Object.getOwnPropertyDescriptor(
    window,
    'localStorage',
) as PropertyDescriptor;

const replaceStorage = (value: unknown) => {
    Object.defineProperty(window, 'localStorage', {
        configurable: true,
        get: () => value,
    });
};

const throwOnStorageAccess = () => {
    Object.defineProperty(window, 'localStorage', {
        configurable: true,
        get: () => {
            throw new DOMException('The operation is insecure.');
        },
    });
};

afterEach(() => {
    Object.defineProperty(window, 'localStorage', realStorage);
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
        throwOnStorageAccess();

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

// The invariant that actually regressed is that nothing on the boot path reads
// storage unguardedly — a helper that is safe on its own does not keep someone
// from going back to a bare localStorage call in these two functions.
describe('boot initializers', () => {
    // jsdom ships no matchMedia; the boot path needs one to get as far as the
    // storage read this guards.
    beforeEach(() => {
        window.matchMedia = ((query: string) => ({
            matches: false,
            media: query,
            addEventListener: () => {},
            removeEventListener: () => {},
        })) as unknown as typeof window.matchMedia;
    });

    it.each([
        ['a null localStorage', () => replaceStorage(null)],
        ['a localStorage that throws on access', throwOnStorageAccess],
    ])('start the app with %s', (_name, breakStorage) => {
        breakStorage();

        expect(() => initializeTheme()).not.toThrow();
        expect(() => initializeChartColorScheme()).not.toThrow();
    });

    it('leaves the theme the server already applied alone when nothing is stored', () => {
        replaceStorage(null);
        document.cookie = 'appearance=dark';

        initializeTheme();

        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });
});
