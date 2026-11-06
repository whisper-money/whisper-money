import { type AccountFormData } from '@/components/accounts/account-form';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { StepManualAccount } from './step-manual-account';

const { captureEvent } = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent }));
vi.mock('@/lib/csrf', () => ({ getCsrfToken: () => 'test-token' }));

// The form itself is covered by its own tests; here only which variant the
// step asks for, and what it sends, matter.
vi.mock('@/components/accounts/account-form', () => ({
    AccountForm: ({
        compact,
        onChange,
    }: {
        compact?: boolean;
        onChange: (data: AccountFormData) => void;
    }) => {
        onChange({
            displayName: 'Main account',
            bankId: null,
            type: 'checking',
            currencyCode: 'EUR',
            customBank: null,
            balance: 250000,
            investedAmount: null,
            realEstate: null,
            loan: null,
            creditLimit: null,
        });

        return (
            <div
                data-testid="account-form"
                data-compact={String(Boolean(compact))}
            />
        );
    },
}));

function renderStep(isFirstAccount: boolean) {
    const onAccountCreated = vi.fn();
    const { container } = render(
        <StepManualAccount
            banks={[]}
            isFirstAccount={isFirstAccount}
            onAccountCreated={onAccountCreated}
        />,
    );

    return { container, onAccountCreated };
}

async function submit(container: HTMLElement) {
    await act(async () => {
        fireEvent.submit(container.querySelector('#onboarding-account')!);
    });
}

describe('StepManualAccount', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ id: 'account-1' }),
        }) as unknown as typeof fetch;
    });

    it('opens the first account on the short form', () => {
        renderStep(true);

        expect(screen.getByTestId('account-form')).toHaveAttribute(
            'data-compact',
            'true',
        );
        expect(screen.getByText('More options')).toBeInTheDocument();
    });

    it('opens the full form for the first account on "More options"', () => {
        renderStep(true);

        fireEvent.click(screen.getByText('More options'));

        expect(screen.getByTestId('account-form')).toHaveAttribute(
            'data-compact',
            'false',
        );
        expect(screen.queryByText('More options')).not.toBeInTheDocument();
    });

    it('keeps the full form for every account after the first', () => {
        renderStep(false);

        expect(screen.getByTestId('account-form')).toHaveAttribute(
            'data-compact',
            'false',
        );
        expect(screen.queryByText('More options')).not.toBeInTheDocument();
    });

    it('sends the short payload and reports the short form', async () => {
        const { container, onAccountCreated } = renderStep(true);

        await submit(container);

        const [, request] = vi.mocked(global.fetch).mock.calls[0];
        expect(JSON.parse(String(request?.body))).toEqual({
            name: 'Main account',
            type: 'checking',
            currency_code: 'EUR',
            balance: 250000,
        });
        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_account_created',
            { account_type: 'checking', form: 'short' },
        );
        expect(onAccountCreated).toHaveBeenCalledWith(
            expect.objectContaining({ type: 'checking', hasBalance: true }),
        );
    });

    it('reports the full form once "More options" was opened', async () => {
        const { container } = renderStep(true);

        fireEvent.click(screen.getByText('More options'));
        await submit(container);

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_account_created',
            { account_type: 'checking', form: 'full' },
        );
    });

    it('reports the full form for a later account', async () => {
        const { container } = renderStep(false);

        await submit(container);

        expect(captureEvent).toHaveBeenCalledWith(
            'onboarding_account_created',
            { account_type: 'checking', form: 'full' },
        );
    });
});
