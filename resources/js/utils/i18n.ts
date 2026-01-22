import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Custom hook to access translations.
 * This must be called from within a React component.
 *
 * Usage:
 * const t = useTranslations();
 * return <div>{t('Save')}</div>
 */
export function useTranslations() {
    const { translations } = usePage<SharedData>().props;

    return function translate(
        key: string,
        replacements?: Record<string, string | number>,
    ): string {
        // Get translation from shared props (returns Spanish if locale is 'es', otherwise returns key)
        let translation = translations[key] ?? key;

        // Replace :placeholders with values
        if (replacements) {
            Object.entries(replacements).forEach(([placeholder, value]) => {
                translation = translation.replace(
                    `:${placeholder}`,
                    String(value),
                );
            });
        }

        return translation;
    };
}

// Alias for shorter usage
export const __ = useTranslations;
