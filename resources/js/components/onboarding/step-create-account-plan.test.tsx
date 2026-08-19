import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { StepCreateAccount } from './step-create-account';

const PLAN_WARNING =
    "Connected accounts are a Standard Plan feature. You'll choose a plan at the end of the onboarding.";

vi.mock('@inertiajs/react', async () => {
    const { pageProps } = await import('@/lib/onboarding-page-props');

    return { usePage: () => ({ props: pageProps }) };
});

// The manual form pulls in the account form's own data fetching, which has
// nothing to do with the plan intent.
vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: () => <div data-testid="account-form" />,
}));

function renderStep(signupPlan: 'free' | 'paid' | null) {
    render(
        <StepCreateAccount
            banks={[]}
            isFirstAccount
            signupPlan={signupPlan}
            onAccountCreated={vi.fn()}
        />,
    );
}

describe('StepCreateAccount plan intent', () => {
    it('offers no connected option to a free signup', () => {
        renderStep('free');

        expect(screen.queryByText('Connected')).not.toBeInTheDocument();
        expect(screen.getByTestId('account-form')).toBeInTheDocument();
    });

    it('offers the connected option without the plan warning to a paid signup', () => {
        renderStep('paid');

        expect(screen.getByText('Connected')).toBeInTheDocument();
        expect(screen.queryByText(PLAN_WARNING)).not.toBeInTheDocument();
    });

    it('keeps the connected option and the plan warning for every other signup', () => {
        renderStep(null);

        expect(screen.getByText('Connected')).toBeInTheDocument();
        expect(screen.getByText(PLAN_WARNING)).toBeInTheDocument();
    });
});
