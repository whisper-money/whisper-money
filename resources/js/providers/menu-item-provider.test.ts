import { type Features } from '@/types';
import { describe, expect, it } from 'vitest';
import { footerNavItems, getMainNavItems } from './menu-item-provider';

const features = (overrides: Partial<Features> = {}) =>
    ({ cashflow: true, forecast: false, ...overrides }) as Features;

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

    it('puts Forecast after Cashflow with the flag on, kept out of the mobile bar', () => {
        const items = getMainNavItems(features({ forecast: true }), 'es');

        expect(items.map((item) => item.title)).toEqual([
            'Dashboard',
            'Cashflow',
            'Forecast',
            'Accounts',
            'Transactions',
            'Planning',
        ]);
        expect(items.find((item) => item.title === 'Forecast')?.mobile).toBe(
            false,
        );
        expect(
            items
                .filter((item) => item.mobile !== false)
                .map((item) => item.mobileTitle),
        ).toEqual(['Inicio', 'Cashflow', 'Cuentas', 'Movim.', 'Plan']);
    });
});
