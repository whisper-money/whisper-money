import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Translation hook - use directly in components.
 * This must be called from within a React component.
 *
 * Usage:
 * import { __ } from '@/utils/i18n';
 * return <div>{__('Save')}</div>
 */
export function useTranslation(
    key: string,
    replacements?: Record<string, string | number>,
): string {
    const { translations } = usePage<SharedData>().props;

    // Get translation from shared props (returns Spanish if locale is 'es', otherwise returns key)
    let translation = translations[key] ?? key;

    // Replace :placeholders with values
    if (replacements) {
        Object.entries(replacements).forEach(([placeholder, value]) => {
            translation = translation.replace(`:${placeholder}`, String(value));
        });
    }

    return translation;
}

// Alias for shorter usage (still follows React hooks rules)
export const __ = useTranslation;
