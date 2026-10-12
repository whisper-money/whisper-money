import { type ColorScheme } from '@/lib/landing-video';
import { useEffect, useState } from 'react';

const readScheme = (): ColorScheme =>
    document.documentElement.classList.contains('dark') ? 'dark' : 'light';

/**
 * The color scheme the visitor actually sees, read from the `dark` class on
 * `<html>`. That class is the single source of truth: the server sets it for an
 * explicit preference, the inline script and `initializeTheme` set it for
 * "system", and the appearance toggle flips it. Watching the class follows
 * every one of those, OS theme changes included.
 *
 * Null until mounted: the server cannot know the scheme for "system", so
 * anything that depends on it must wait for the client.
 */
export function useDocumentColorScheme(): ColorScheme | null {
    const [scheme, setScheme] = useState<ColorScheme | null>(null);

    useEffect(() => {
        setScheme(readScheme());

        const observer = new MutationObserver(() => setScheme(readScheme()));
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });

        return () => observer.disconnect();
    }, []);

    return scheme;
}
