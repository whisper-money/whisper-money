import { describe, expect, it } from 'vitest';
import { landingVideoLanguage, landingVideoSources } from './landing-video';

describe('landingVideoLanguage', () => {
    it('gives every Spanish variant the Spanish cut', () => {
        for (const language of ['es', 'es-ES', 'es-MX', 'ES']) {
            expect(landingVideoLanguage(language)).toBe('es');
        }
    });

    it('falls back to English for every other language', () => {
        for (const language of ['en', 'en-US', 'fr', 'de', '']) {
            expect(landingVideoLanguage(language)).toBe('en');
        }
    });
});

describe('landingVideoSources', () => {
    it('picks the video and poster for the language and color scheme', () => {
        expect(landingVideoSources('es', 'dark')).toEqual({
            video: '/images/landing_videos/product-es-dark.mp4',
            tour: '/images/landing_videos/product-es-dark.mp4',
            poster: '/images/landing_videos/product-es-dark.jpg',
        });
        expect(landingVideoSources('fr', 'light')).toEqual({
            video: '/images/landing_videos/product-en-light.mp4',
            tour: '/images/landing_videos/product-en-light.mp4',
            poster: '/images/landing_videos/product-en-light.jpg',
        });
    });
});
