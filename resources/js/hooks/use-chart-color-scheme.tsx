import { readStoredValue, writeStoredValue } from '@/lib/safe-storage';
import { ChartColorScheme, SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'chart-color-scheme';

const setCookie = (name: string, value: string, days = 365) => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

/** Puts a chart theme on <html>: neutral is the absence of the attribute. */
export const applyColorScheme = (scheme: ChartColorScheme) => {
    if (typeof document === 'undefined') {
        return;
    }

    if (scheme === 'neutral') {
        document.documentElement.removeAttribute('data-chart-color');
    } else {
        document.documentElement.setAttribute('data-chart-color', scheme);
    }
};

export function initializeChartColorScheme() {
    if (typeof window === 'undefined') {
        return;
    }

    // Only override what the server already rendered from the cookie: with no
    // usable storage this would otherwise reset every user to "colorful",
    // leaving the CSS palette fighting the scheme the charts draw with.
    const saved = readStoredValue(STORAGE_KEY) as ChartColorScheme | null;

    if (saved) {
        applyColorScheme(saved);
    }
}

export function useChartColorScheme() {
    const { chartColorScheme: serverScheme } = usePage<SharedData>().props;
    const [scheme, setScheme] = useState<ChartColorScheme>('colorful');

    const updateScheme = useCallback((newScheme: ChartColorScheme) => {
        setScheme(newScheme);
        writeStoredValue(STORAGE_KEY, newScheme);
        setCookie(STORAGE_KEY, newScheme);
        applyColorScheme(newScheme);
    }, []);

    useEffect(() => {
        const saved = readStoredValue(STORAGE_KEY) as ChartColorScheme | null;
        updateScheme(saved || serverScheme || 'colorful');
    }, [serverScheme, updateScheme]);

    return { scheme, updateScheme } as const;
}
