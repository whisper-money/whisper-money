import { __ } from '@/utils/i18n';

/**
 * "BBVA and Amex", "BBVA, Amex and Cash": a list the way a sentence says it.
 * Joined through the translations rather than `Intl.ListFormat`: the locale
 * the app formats numbers in can be a different language from the one the
 * sentence around the list is written in.
 */
export function joinNames(names: string[]): string {
    if (names.length <= 1) {
        return names[0] ?? '';
    }

    return __(':list and :last', {
        list: names.slice(0, -1).join(', '),
        last: names[names.length - 1],
    });
}
