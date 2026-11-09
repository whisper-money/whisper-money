import { pricingFixture } from '@/lib/pricing-fixture';
import { type PricingConfig } from '@/types/pricing';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepGate, type GateCopyArm } from './step-gate';

const mocks = vi.hoisted(() => ({
    props: {
        current: {} as {
            pricing: PricingConfig;
            gateCopyArm?: GateCopyArm | null;
        },
    },
    captureEvent: vi.fn(),
    registerProperties: vi.fn(),
    unregisterProperty: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en', ...mocks.props.current } }),
}));

vi.mock('@/lib/posthog', () => ({
    captureEvent: mocks.captureEvent,
    registerProperties: mocks.registerProperties,
    unregisterProperty: mocks.unregisterProperty,
}));

function renderGate(
    arm: GateCopyArm | null,
    {
        kind = 'bank',
        pricing = pricingFixture,
    }: { kind?: 'bank' | 'broker' | 'ai'; pricing?: PricingConfig } = {},
) {
    mocks.props.current = { pricing, gateCopyArm: arm };

    return render(<StepGate kind={kind} onDecline={vi.fn()} />);
}

function checkoutHref(): string {
    return screen.getByTestId('start-plan').getAttribute('href') ?? '';
}

beforeEach(() => {
    mocks.captureEvent.mockClear();
    mocks.registerProperties.mockClear();
    mocks.unregisterProperty.mockClear();
});

describe('StepGate copy experiment', () => {
    it('frames the yearly plan as a free trial, price in the fine print', () => {
        renderGate('trial_yearly');

        expect(
            screen.getByText('Connect your bank free for 14 days'),
        ).toBeTruthy();
        expect(screen.getByTestId('start-plan').textContent).toBe(
            'Start free trial',
        );
        expect(
            screen.getByText(
                'Free for 14 days, then €53.94 a year. Cancel before then and you are not charged.',
            ),
        ).toBeTruthy();
        expect(checkoutHref()).toContain('plan=yearly');
        expect(checkoutHref()).toContain('source=onboarding_bank');
    });

    it('frames the monthly plan as a free trial, monthly preselected', () => {
        renderGate('trial_monthly');

        expect(
            screen.getByText('Connect your bank free for 7 days'),
        ).toBeTruthy();
        expect(
            screen.getByText(
                'Free for 7 days, then €8.99 a month. Cancel before then and you are not charged.',
            ),
        ).toBeTruthy();
        expect(checkoutHref()).toContain('plan=monthly');
    });

    it('names the trial of the plan in hand when the user switches', () => {
        renderGate('trial_monthly');

        fireEvent.click(screen.getByText('Yearly'));

        expect(
            screen.getByText('Connect your bank free for 14 days'),
        ).toBeTruthy();
        expect(checkoutHref()).toContain('plan=yearly');
    });

    it('records the plan the arm preselected, not the one bought', () => {
        renderGate('trial_monthly');

        fireEvent.click(screen.getByText('Yearly'));
        fireEvent.click(screen.getByTestId('start-plan'));

        expect(checkoutHref()).toContain('preselected_plan=monthly');
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'onboarding_gate_checkout_started',
            {
                gate: 'bank',
                plan: 'yearly',
                preselected_plan: 'monthly',
                price_tier: 'standard',
                arm: 'trial_monthly',
            },
        );
    });

    /**
     * Out of the experiment the gate has no plan of its own, so it follows the
     * configured default: flipping `SUBSCRIPTIONS_DEFAULT_PLAN` moves it too.
     */
    it('preselects the configured default with no arm', () => {
        renderGate(null, {
            pricing: { ...pricingFixture, defaultPlan: 'monthly' },
        });

        expect(checkoutHref()).toMatch(/[?&]plan=monthly/);
        expect(checkoutHref()).toContain('preselected_plan=monthly');
        expect(screen.getByTestId('start-plan').textContent).toBe(
            'Start Standard — free for 7 days',
        );
    });

    it('says account rather than bank on the broker gate', () => {
        renderGate('trial_yearly', { kind: 'broker' });

        expect(
            screen.getByText('Connect your account free for 14 days'),
        ).toBeTruthy();
    });

    it('reports the arm on decline and on the way to checkout', () => {
        renderGate('trial_monthly');

        fireEvent.click(screen.getByTestId('start-plan'));
        fireEvent.click(screen.getByTestId('decline-gate'));

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'onboarding_gate_checkout_started',
            {
                gate: 'bank',
                plan: 'monthly',
                preselected_plan: 'monthly',
                price_tier: 'standard',
                arm: 'trial_monthly',
            },
        );
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'onboarding_gate_declined',
            { gate: 'bank', arm: 'trial_monthly' },
        );
    });

    it('leaves the AI gate out of the experiment', () => {
        renderGate('trial_yearly', { kind: 'ai' });

        expect(screen.getByText('AI sorting needs Standard')).toBeTruthy();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'onboarding_gate_viewed',
            { gate: 'ai' },
        );
        expect(mocks.registerProperties).not.toHaveBeenCalled();
        expect(mocks.unregisterProperty).not.toHaveBeenCalled();
    });

    /**
     * No arm is what the server hands down once the experiment is switched
     * off. A browser tagged before stops
     * reporting the arm it no longer sees.
     */
    it('draws the plain gate, unlabelled, when no arm is handed down', () => {
        renderGate(null);

        expect(
            screen.getByText('Connecting a bank needs Standard'),
        ).toBeTruthy();
        expect(checkoutHref()).toContain('plan=yearly');
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'onboarding_gate_viewed',
            { gate: 'bank' },
        );
        expect(mocks.registerProperties).not.toHaveBeenCalled();
        expect(mocks.unregisterProperty).toHaveBeenCalledWith(
            'onboarding_gate_arm',
        );
    });
});
