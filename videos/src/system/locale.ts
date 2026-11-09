import { setTranslations } from '@/utils/i18n';
import es from '../../../lang/es.json';

/** Languages a feature video is rendered in. English strings are the keys. */
export type VideoLocale = 'es' | 'en';

const TRANSLATIONS: Record<VideoLocale, Record<string, string>> = {
    es,
    en: {},
};

/** The region amounts and dates are formatted in, as the app's `locale` prop. */
export const FORMAT_LOCALE: Record<VideoLocale, string> = {
    es: 'es-ES',
    en: 'en-US',
};

/** Makes `__()` in app components answer in the video's language. */
export function applyLocale(locale: VideoLocale): void {
    setTranslations(TRANSLATIONS[locale]);
}
