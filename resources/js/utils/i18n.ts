import type { SharedData } from '@/types';
import { router } from '@inertiajs/react';

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
    const translations =
        (router.page?.props as SharedData | undefined)?.translations ?? {};

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
