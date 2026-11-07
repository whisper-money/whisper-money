import { pricingFixture } from '@/lib/pricing-fixture';
import { type PricingConfig } from '@/types/pricing';
import { describe, expect, it } from 'vitest';
import { offeredPriceTier, plansForTier } from './price-tier';

const latamPlans = {
    monthly: { ...pricingFixture.plans.monthly, price: 3.99 },
    yearly: { ...pricingFixture.plans.yearly, price: 23.88 },
};

/** What the server shares while the latam price is offered. */
function withLatam(overrides: Partial<PricingConfig> = {}): PricingConfig {
    return {
        ...pricingFixture,
        latam: {
            plans: latamPlans,
            timezones: ['America/Bogota', 'America/Mexico_City'],
        },
        offeredTier: null,
        ...overrides,
    };
}

describe('offeredPriceTier', () => {
    it('never quotes latam while the server does not offer it', () => {
        expect(offeredPriceTier(pricingFixture, false, 'America/Bogota')).toBe(
            'standard',
        );
        expect(
            offeredPriceTier(
                { ...pricingFixture, offeredTier: 'latam' },
                true,
                'America/Bogota',
            ),
        ).toBe('standard');
    });

    it('places an anonymous visitor by the timezone list the server shares', () => {
        expect(offeredPriceTier(withLatam(), false, 'America/Bogota')).toBe(
            'latam',
        );
        expect(offeredPriceTier(withLatam(), false, 'America/New_York')).toBe(
            'standard',
        );
        expect(offeredPriceTier(withLatam(), false, 'Europe/Madrid')).toBe(
            'standard',
        );
        // On the server and during hydration the timezone is unknown.
        expect(offeredPriceTier(withLatam(), false, null)).toBe('standard');
    });

    it('quotes a signed-in user their assigned tier, whatever the browser says', () => {
        expect(
            offeredPriceTier(
                withLatam({ offeredTier: 'latam' }),
                true,
                'Europe/Madrid',
            ),
        ).toBe('latam');
        expect(
            offeredPriceTier(
                withLatam({ offeredTier: 'high' }),
                true,
                'America/Bogota',
            ),
        ).toBe('standard');
    });
});

describe('plansForTier', () => {
    it('returns the plans of the tier asked for', () => {
        expect(plansForTier(withLatam(), 'latam')).toBe(latamPlans);
        expect(plansForTier(withLatam(), 'standard')).toBe(
            pricingFixture.plans,
        );
    });

    it('falls back to the standard plans when no latam price is shared', () => {
        expect(plansForTier(pricingFixture, 'latam')).toBe(
            pricingFixture.plans,
        );
    });
});
