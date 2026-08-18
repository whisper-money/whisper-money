import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { CONNECT_PROVIDERS } from './connect-providers';

/**
 * The registry's copy reaches __() through variables, so the Spanish
 * completeness check in tests/Feature/LocalizationTest.php — which only
 * extracts literal __('…') arguments from source — never sees any of it. A
 * provider added or a sentence reworded here would ship untranslated with a
 * green CI. This closes that gap.
 */
const spanish = JSON.parse(readFileSync('lang/es.json', 'utf8')) as Record<
    string,
    string
>;

const copyStrings = CONNECT_PROVIDERS.flatMap((provider) => [
    provider.headerDescription,
    provider.cardDescription,
    provider.help.before,
    provider.help.link,
    ...(provider.help.after ? [provider.help.after] : []),
    ...provider.fields.flatMap((field) => [
        field.label,
        ...(field.placeholder ? [field.placeholder] : []),
    ]),
]);

describe('connect provider copy', () => {
    it('is fully translated into Spanish', () => {
        const missing = copyStrings.filter(
            (key) => !Object.hasOwn(spanish, key),
        );

        expect(missing).toEqual([]);
    });
});
