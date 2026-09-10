import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useLocale } from './use-locale';

const props = { locale: 'en-US' };

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props }),
}));

describe('useLocale', () => {
    beforeEach(() => {
        props.locale = 'en-US';
    });

    it('hands back the region the server shared', () => {
        props.locale = 'es-MX';

        expect(renderHook(() => useLocale()).result.current).toBe('es-MX');
    });

    it('falls back rather than letting a malformed tag blank the screen', () => {
        // Every Intl constructor throws RangeError on these, and the throw
        // costs the whole page instead of one badly written number.
        for (const malformed of ['es_MX', '', 'not a locale']) {
            props.locale = malformed;

            expect(renderHook(() => useLocale()).result.current).toBe('en-US');
        }
    });

    it('leaves a well-formed tag it does not know alone', () => {
        // Intl falls back on its own for these, so there is nothing to catch.
        props.locale = 'xx-YY';

        expect(renderHook(() => useLocale()).result.current).toBe('xx-YY');
    });
});
