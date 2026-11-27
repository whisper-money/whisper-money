import { type Features } from '@/types';
import { describe, expect, it } from 'vitest';
import {
    footerNavItems,
    getMainNavItems,
    getMobileNavItems,
} from './menu-item-provider';

const features = (overrides: Partial<Features> = {}) =>
    ({ cashflow: true, forecast: false, ...overrides }) as Features;

const mobileBar = (flags: Partial<Features>, locale: string) =>
    getMobileNavItems(getMainNavItems(features(flags), locale)).map(
        (item) => item.mobileTitle,
    );

describe('menu item provider', () => {
    it('does not include community in sidebar footer nav items', () => {
        expect(footerNavItems).not.toContainEqual(
            expect.objectContaining({ title: 'Community' }),
        );
    });

    it('leaves Forecast out with the flag off', () => {
        expect(
            getMainNavItems(features(), 'en').map((item) => item.title),
        ).toEqual([
            'Dashboard',
            'Cashflow',
            'Accounts',
            'Transactions',
            'Planning',
        ]);
    });

    it('keeps the mobile bar as it was with the flag off, Accounts included', () => {
        expect(mobileBar({}, 'es')).toEqual([
            'Inicio',
            'Cashflow',
            'Cuentas',
            'Movim.',
            'Plan',
        ]);
        expect(mobileBar({}, 'en')).toEqual([
            'Home',
            'Cashflow',
            'Accounts',
            'Movements',
            'Plan',
        ]);
    });

    it('reads the language from a regional locale', () => {
        expect(mobileBar({}, 'es-ES')).toEqual(mobileBar({}, 'es'));
        expect(mobileBar({}, 'es-MX')).toEqual(mobileBar({}, 'es'));
        expect(mobileBar({}, 'en-GB')).toEqual(mobileBar({}, 'en'));
    });

    it('falls back to English for a language without labels', () => {
        expect(mobileBar({}, 'de-DE')).toEqual([
            'Home',
            'Cashflow',
            'Accounts',
            'Movements',
            'Plan',
        ]);
    });

    it('labels the mobile bar in French', () => {
        const flagOff = [
            'Accueil',
            'Trésorerie',
            'Comptes',
            'Opérations',
            'Plan',
        ];
        const flagOn = [
            'Accueil',
            'Trésorerie',
            'Prévision',
            'Opérations',
            'Plan',
        ];

        expect(mobileBar({}, 'fr')).toEqual(flagOff);
        expect(mobileBar({}, 'fr-FR')).toEqual(flagOff);
        expect(mobileBar({ forecast: true }, 'fr')).toEqual(flagOn);
        expect(mobileBar({ forecast: true }, 'fr-FR')).toEqual(flagOn);
    });

    it('puts Forecast after Cashflow with the flag on, in both the sidebar and the mobile bar', () => {
        const items = getMainNavItems(features({ forecast: true }), 'es');

        expect(items.map((item) => item.title)).toEqual([
            'Dashboard',
            'Cashflow',
            'Forecast',
            'Accounts',
            'Transactions',
            'Planning',
        ]);
    });

    it('swaps Accounts for Forecast in the mobile bar with the flag on', () => {
        expect(mobileBar({ forecast: true }, 'es')).toEqual([
            'Inicio',
            'Cashflow',
            'Previsión',
            'Movim.',
            'Plan',
        ]);
        expect(mobileBar({ forecast: true }, 'en')).toEqual([
            'Home',
            'Cashflow',
            'Forecast',
            'Movements',
            'Plan',
        ]);
    });
});
