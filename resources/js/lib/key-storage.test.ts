import { afterEach, describe, expect, it } from 'vitest';
import { clearKey, getStoredKey, storeKey } from './key-storage';

type StorageArea = 'localStorage' | 'sessionStorage';

const AREAS: StorageArea[] = ['localStorage', 'sessionStorage'];

const realStorages = AREAS.map(
    (area) =>
        [
            area,
            Object.getOwnPropertyDescriptor(window, area) as PropertyDescriptor,
        ] as const,
);

const replaceStorage = (area: StorageArea, value: unknown) => {
    Object.defineProperty(window, area, {
        configurable: true,
        get: () => value,
    });
};

const workingStorage = () => {
    const stored = new Map<string, string>();

    return {
        getItem: (key: string) => stored.get(key) ?? null,
        setItem: (key: string, value: string) => stored.set(key, value),
        removeItem: (key: string) => stored.delete(key),
    };
};

const throwOnAccess = (area: StorageArea) => {
    Object.defineProperty(window, area, {
        configurable: true,
        get: () => {
            throw new DOMException(
                `Failed to read the '${area}' property from 'Window': Access is denied for this document.`,
            );
        },
    });
};

const breakBothStorages = {
    'storages that are null': () =>
        AREAS.forEach((area) => replaceStorage(area, null)),
    'storages that throw on access': () => AREAS.forEach(throwOnAccess),
};

afterEach(() => {
    realStorages.forEach(([area, descriptor]) =>
        Object.defineProperty(window, area, descriptor),
    );
});

describe('key storage with working storage', () => {
    const useWorkingStorages = () =>
        AREAS.forEach((area) => replaceStorage(area, workingStorage()));

    it('keeps a persistent key in localStorage and a session key in sessionStorage', () => {
        useWorkingStorages();

        storeKey('persistent-key', true);

        expect(window.localStorage.getItem('encryption_key')).toBe(
            'persistent-key',
        );
        expect(window.sessionStorage.getItem('encryption_key')).toBeNull();

        clearKey();

        storeKey('session-key', false);

        expect(window.sessionStorage.getItem('encryption_key')).toBe(
            'session-key',
        );
        expect(window.localStorage.getItem('encryption_key')).toBeNull();
    });

    it('prefers the session key over the persistent one', () => {
        useWorkingStorages();
        window.localStorage.setItem('encryption_key', 'persistent-key');
        window.sessionStorage.setItem('encryption_key', 'session-key');

        expect(getStoredKey()).toBe('session-key');
    });

    it('clears the key from both areas', () => {
        useWorkingStorages();
        window.localStorage.setItem('encryption_key', 'persistent-key');
        window.sessionStorage.setItem('encryption_key', 'session-key');

        clearKey();

        expect(getStoredKey()).toBeNull();
    });
});

// PHP-LARAVEL-5V: a browser blocking site data throws SecurityError on the
// property read itself, and clearKey() runs in <Login>'s mount effect — the
// unguarded version took down the page a locked-out user needs.
describe('key storage the browser refuses to serve', () => {
    it.each(Object.entries(breakBothStorages))(
        'reads nothing and clears nothing with %s, instead of throwing',
        (_name, breakStorage) => {
            breakStorage();

            expect(getStoredKey()).toBeNull();
            expect(() => clearKey()).not.toThrow();
        },
    );

    it.each(Object.entries(breakBothStorages))(
        'drops a write with %s instead of throwing',
        (_name, breakStorage) => {
            breakStorage();

            expect(() => storeKey('key', true)).not.toThrow();
            expect(() => storeKey('key', false)).not.toThrow();
        },
    );

    it('drops a write when setItem throws, e.g. an exhausted quota', () => {
        replaceStorage('localStorage', {
            getItem: () => null,
            setItem: () => {
                throw new DOMException('QuotaExceededError');
            },
        });

        expect(() => storeKey('key', true)).not.toThrow();
    });

    // Losing the session copy must not leave the persistent one behind on a
    // shared browser, so clearKey keeps going past a throw.
    it('still clears the persistent key when the session area throws', () => {
        throwOnAccess('sessionStorage');
        replaceStorage('localStorage', workingStorage());
        window.localStorage.setItem('encryption_key', 'persistent-key');

        clearKey();

        expect(window.localStorage.getItem('encryption_key')).toBeNull();
    });
});
