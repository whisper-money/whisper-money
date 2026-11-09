import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { useEffect, useState, type ReactNode } from 'react';
import { AbsoluteFill, continueRender, delayRender } from 'remotion';
import { setPageProps } from './inertia-mock';
import { applyLocale, FORMAT_LOCALE, type VideoLocale } from './locale';
import { BRAND } from './theme';

/** Weights the videos write in; each must be loaded before a frame is taken. */
const FONTS = [
    '400 16px "IBM Plex Sans"',
    '500 16px "IBM Plex Sans"',
    '600 16px "IBM Plex Sans"',
    '700 16px "IBM Plex Sans"',
    '600 16px "Google Sans Code"',
];

/** Holds the render until the app's self-hosted fonts are in. */
function useFontsReady(): void {
    const [handle] = useState(() => delayRender('Loading fonts'));

    useEffect(() => {
        Promise.all(FONTS.map((font) => document.fonts.load(font)))
            .then(() => document.fonts.ready)
            .finally(() => continueRender(handle));
    }, [handle]);
}

/**
 * The dark canvas every scene sits on, with what app components expect from
 * the page props and the providers app.tsx mounts around a page.
 */
export function VideoRoot({
    locale,
    currency,
    children,
}: {
    locale: VideoLocale;
    currency: string;
    children: ReactNode;
}) {
    useFontsReady();
    // On <html> as in the app: the theme tokens resolve their variables there,
    // so a `dark` class further down would not reach them.
    useEffect(() => {
        document.documentElement.classList.add('dark');
    }, []);
    // Set during render, before any child reads them, and again on every
    // render: switching compositions in the studio re-renders this first.
    applyLocale(locale);
    setPageProps({
        locale: FORMAT_LOCALE[locale],
        auth: { user: { currency_code: currency } },
    });

    return (
        <AbsoluteFill
            className="font-sans text-foreground antialiased"
            style={{ backgroundColor: BRAND.background }}
        >
            <PrivacyModeProvider>{children}</PrivacyModeProvider>
        </AbsoluteFill>
    );
}
