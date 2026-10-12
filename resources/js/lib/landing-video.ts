export type ColorScheme = 'light' | 'dark';

export type LandingVideoSources = {
    video: string;
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

    return { video: `${base}.mp4`, poster: `${base}.jpg` };
}
