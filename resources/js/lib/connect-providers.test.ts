import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { CONNECT_PROVIDERS, isProviderInBeta } from './connect-providers';

/**
 * The registry's copy reaches __() through variables, so the Spanish
 * completeness check in tests/Feature/LocalizationTest.php — which only
 * extracts string literals passed straight to __() — never sees any of it. A
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

describe('connect provider beta flags', () => {
    // Fails on the day a beta period ends, so the stale `betaUntil` is removed
    // from the registry instead of silently doing nothing.
    it.each(
        CONNECT_PROVIDERS.filter((provider) => provider.betaUntil).map(
            (provider) => [provider.providerKey, provider] as const,
        ),
    )('%s is still within its beta period', (_key, provider) => {
        expect(isProviderInBeta(provider)).toBe(true);
    });

    it('ends the beta on the betaUntil date', () => {
        const provider = { ...CONNECT_PROVIDERS[0], betaUntil: '2026-11-23' };

        expect(
            isProviderInBeta(provider, new Date('2026-11-22T23:00:00Z')),
        ).toBe(true);
        expect(
            isProviderInBeta(provider, new Date('2026-11-23T00:00:00Z')),
        ).toBe(false);
    });
});

describe('connect provider copy', () => {
    it('is fully translated into Spanish', () => {
        const missing = copyStrings.filter(
            (key) => !Object.hasOwn(spanish, key),
        );

        expect(missing).toEqual([]);
    });
});
