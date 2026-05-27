import { describe, expect, it } from 'vitest';
import { footerNavItems } from './menu-item-provider';

describe('menu item provider', () => {
    it('does not include community in sidebar footer nav items', () => {
        expect(footerNavItems).not.toContainEqual(
            expect.objectContaining({ title: 'Community' }),
        );
    });
});
