export type ColorScheme = 'light' | 'dark';

export type LandingVideoSources = {
    /** The muted loop in the hero. */
    video: string;
    /** The full tour the visitor opens on purpose, played with its audio. */
    tour: string;
    poster: string;
};

/**
 * The product video is only rendered in English and Spanish: every Spanish
 * variant gets the Spanish cut, every other language falls back to English.
 */
export function landingVideoLanguage(language: string): 'en' | 'es' {
    return language.toLowerCase().startsWith('es') ? 'es' : 'en';
}

export function landingVideoSources(
    language: string,
    scheme: ColorScheme,
): LandingVideoSources {
    const base = `/images/landing_videos/product-${landingVideoLanguage(language)}-${scheme}`;

    // The renders carry no audio track yet, so the tour is the loop's file. A
    // with-audio render only needs its own path here.
    return { video: `${base}.mp4`, tour: `${base}.mp4`, poster: `${base}.jpg` };
}
