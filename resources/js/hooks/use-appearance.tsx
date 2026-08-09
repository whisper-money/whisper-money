import {
    addMediaQueryListener,
    removeMediaQueryListener,
} from '@/lib/media-query';
import { readStoredValue, writeStoredValue } from '@/lib/safe-storage';
import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => {
    if (typeof window === 'undefined') {
        return false;
    }

    return window.matchMedia('(prefers-color-scheme: dark)').matches;
};

/**
 * The preference cookie is the only channel that survives when localStorage is
 * unavailable, and the server already renders the page from it. Reading it back
 * keeps a no-storage user on the theme they chose instead of resetting them to
 * "system" — and to light, on a light-OS device — on every load.
 */
const readCookie = (name: string): string | null => {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
};

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

const applyTheme = (appearance: Appearance) => {
    if (typeof document === 'undefined') return;

    const isDark =
        appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
    document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
};

const mediaQuery = () => {
    if (typeof window === 'undefined') {
        return null;
    }

    return window.matchMedia('(prefers-color-scheme: dark)');
};

const storedAppearance = (): Appearance | null =>
    (readStoredValue('appearance') ??
        readCookie('appearance')) as Appearance | null;

const handleSystemThemeChange = () => {
    if (typeof window === 'undefined') return;

    applyTheme(storedAppearance() || 'system');
};

export function initializeTheme() {
    if (typeof window === 'undefined') return;

    applyTheme(storedAppearance() || 'system');

    // Add the event listener for system theme changes...
    const mql = mediaQuery();
    if (mql) {
        addMediaQueryListener(mql, handleSystemThemeChange);
    }
}

export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');

    const updateAppearance = useCallback((mode: Appearance) => {
        setAppearance(mode);

        // Store in localStorage for client-side persistence...
        writeStoredValue('appearance', mode);

        // Store in cookie for SSR...
        setCookie('appearance', mode);

        applyTheme(mode);
    }, []);

    useEffect(() => {
        // Hydrate from what is already persisted — deliberately without writing
        // it back, so mounting this hook cannot overwrite the cookie of a user
        // whose localStorage is unavailable.
        const saved = storedAppearance() || 'system';

        setAppearance(saved);
        applyTheme(saved);

        return () => {
            const mql = mediaQuery();
            if (mql) {
                removeMediaQueryListener(mql, handleSystemThemeChange);
            }
        };
    }, []);

    return { appearance, updateAppearance } as const;
}
