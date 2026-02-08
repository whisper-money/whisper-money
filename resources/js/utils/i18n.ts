let currentTranslations: Record<string, string> = {};

/**
 * Sync translations from Inertia page props.
 * Called from app.tsx on initial load and on every navigation.
 */
export function setTranslations(translations: Record<string, string>): void {
    currentTranslations = translations;
}

/**
 * Translation function - can be used anywhere (components, event handlers, callbacks).
 *
 * Usage:
 * import { __ } from '@/utils/i18n';
 * return <div>{__('Save')}</div>
 */
export function __(
    key: string,
    replacements?: Record<string, string | number>,
): string {
    let translation = currentTranslations[key] ?? key;

    // Replace :placeholders with values
    if (replacements) {
        Object.entries(replacements).forEach(([placeholder, value]) => {
            translation = translation.replace(`:${placeholder}`, String(value));
        });
    }

    return translation;
}
