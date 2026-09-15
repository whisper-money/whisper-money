import { onboardingSummaryResponse } from '@/hooks/use-onboarding-summary.fixture';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { StepComplete } from './step-complete';

const post = vi.fn();

const { get, captureEvent } = vi.hoisted(() => ({
    get: vi.fn(),
    captureEvent: vi.fn(),
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

vi.mock('axios', () => ({
    default: { get, isAxiosError: () => false },
}));

vi.mock('@inertiajs/react', () => ({
    router: { post: (...args: unknown[]) => post(...args) },
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

const renderStep = async (props: Record<string, unknown> = {}) => {
    render(
        <StepComplete
            accountsCreated={2}
            hasConnectedAccount
            signupPlan="paid"
            {...props}
        />,
    );

    await act(async () => {
        await Promise.resolve();
    });
};

type VisitCallbacks = {
    onError?: () => void;
    onFinish?: () => void;
    onNetworkError?: (error: Error) => void;
    onSuccess?: () => void;
};

/**
 * Every way `POST /onboarding/complete` can end without moving the user on.
 * Only the first of them reaches `onError`, which is why resetting the button
 * from there left the last step of onboarding behind a dead, spinning button.
 */
const failures: Array<[string, (callbacks: VisitCallbacks) => void]> = [
    [
        'the server rejects the request',
        (callbacks) => {
            callbacks.onError?.();
            callbacks.onFinish?.();
        },
    ],
    [
        'the request never reaches the server',
        (callbacks) => {
            callbacks.onNetworkError?.(new Error('Network error'));
            callbacks.onFinish?.();
        },
    ],
    [
        'the answer is one Inertia cannot read',
        (callbacks) => {
            callbacks.onFinish?.();
        },
    ],
];

describe('StepComplete', () => {
    afterEach(() => {
        post.mockReset();
        get.mockReset();
        captureEvent.mockReset();
    });

    it('lists everything the user who did it all actually has', async () => {
        get.mockResolvedValue(onboardingSummaryResponse());

        await renderStep({ spendingGuess: 120000 });

        expect(screen.getByText('12 months of history')).toBeInTheDocument();
        expect(
            screen.getByText('903 movements, 28 rules filing them'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('4 accounts, syncing daily'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Everything new sorted from now on'),
        ).toBeInTheDocument();
        expect(screen.getByText('€200 a month put aside')).toBeInTheDocument();
    });

    /**
     * The file importer who declined the target: no bank behind the accounts
     * and no target set, so neither claim may appear. The rest still does.
     */
    it('leaves out the sync and the target nobody set', async () => {
        get.mockResolvedValue(
            onboardingSummaryResponse({
                connected_accounts: 0,
                accounts: 2,
                target: null,
            }),
        );

        await renderStep({ spendingGuess: 120000 });

        expect(screen.getByText('2 accounts')).toBeInTheDocument();
        expect(screen.queryByText(/syncing daily/)).toBeNull();
        expect(screen.queryByText(/put aside/)).toBeNull();
    });

    /**
     * A balance typed in by hand and nothing else. The list is one line long,
     * and every claim the screen could have made is one it cannot.
     */
    it('reports one line for the user who brought one balance', async () => {
        get.mockResolvedValue(
            onboardingSummaryResponse({
                accounts: 1,
                connected_accounts: 0,
                transactions: 0,
                months: 0,
                rules: 0,
                monthly_spending: null,
                recurring_count: 0,
                recurring_amount: 0,
                target: null,
            }),
        );

        await renderStep();

        expect(screen.getByText('1 account')).toBeInTheDocument();
        expect(screen.queryByText(/months of history/)).toBeNull();
        expect(screen.queryByText(/movements/)).toBeNull();
        expect(screen.queryByText(/sorted from now on/)).toBeNull();
        // No guess to set against a month that was never read; what they get
        // instead is the one thing that would fill the rest of the list.
        expect(screen.queryByText(/You came in guessing/)).toBeNull();
        expect(
            screen.getByText(
                'Connect a bank or import a file whenever you like, and the rest of this list fills itself in.',
            ),
        ).toBeInTheDocument();
    });

    it('sets the guess against the real number it answered', async () => {
        get.mockResolvedValue(onboardingSummaryResponse());

        await renderStep({ spendingGuess: 120000 });

        expect(screen.getByText('€1,200')).toBeInTheDocument();
        expect(screen.getByText('€1,847')).toBeInTheDocument();
    });

    // Someone who guessed right gets no consolation paragraph.
    it('says nothing about a gap too small to name', async () => {
        get.mockResolvedValue(onboardingSummaryResponse());

        await renderStep({ spendingGuess: 184700 });

        expect(screen.queryByText(/You came in guessing/)).toBeNull();
    });

    // Nothing was read, so nothing may be claimed — but the user still gets out.
    it('claims nothing when the summary cannot be read', async () => {
        get.mockRejectedValue(new Error('down'));

        await renderStep();

        expect(screen.queryByText(/months of history/)).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Open my dashboard' }),
        ).toBeEnabled();
    });

    it('forgets the stored resume step once onboarding is done', async () => {
        get.mockResolvedValue(onboardingSummaryResponse());
        window.localStorage.setItem('onboarding-step', 'complete');

        await renderStep();

        fireEvent.click(screen.getByRole('button'));

        await act(async () => {
            const callbacks = post.mock.calls[0][2] as VisitCallbacks;
            callbacks.onSuccess?.();
            callbacks.onFinish?.();
        });

        // Left behind, it would drag a returning user back into onboarding.
        expect(window.localStorage.getItem('onboarding-step')).toBeNull();
    });

    it.each(failures)(
        'lets the user try again when %s',
        async (_failure, settle) => {
            get.mockResolvedValue(onboardingSummaryResponse());

            await renderStep();

            fireEvent.click(screen.getByRole('button'));

            expect(post).toHaveBeenCalledOnce();
            expect(screen.getByRole('button')).toBeDisabled();

            await act(async () => {
                settle(post.mock.calls[0][2] as VisitCallbacks);
            });

            // StepButton disables itself while loading, so staying in that
            // state is a one-way door out of onboarding.
            expect(screen.getByRole('button')).toBeEnabled();
        },
    );

    it('reports the completion with what the user set up', async () => {
        get.mockResolvedValue(onboardingSummaryResponse());

        await renderStep();

        fireEvent.click(screen.getByRole('button'));

        await act(async () => {
            const callbacks = post.mock.calls[0][2] as VisitCallbacks;
            callbacks.onSuccess?.();
            callbacks.onFinish?.();
        });

        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith('onboarding_completed', {
            accounts_created: 2,
            has_connected_account: true,
            signup_plan: 'paid',
            target: 20000,
        });
    });

    // A retryable failure is not a completed onboarding, and counting it as one
    // would put the funnel's last step above the number of onboarded users.
    it.each(failures)('reports nothing when %s', async (_failure, settle) => {
        get.mockResolvedValue(onboardingSummaryResponse());

        await renderStep();

        fireEvent.click(screen.getByRole('button'));

        await act(async () => {
            settle(post.mock.calls[0][2] as VisitCallbacks);
        });

        expect(captureEvent).not.toHaveBeenCalled();
    });
});
