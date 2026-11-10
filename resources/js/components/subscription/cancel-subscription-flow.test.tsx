import { act, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    CancelSubscriptionFlow,
    type CancellationFlowAnswer,
    type CancellationOffer,
    type CancellationReason,
} from './cancel-subscription-flow';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    routerPost: vi.fn(),
    captureEvent: vi.fn(),
    errors: {} as Record<string, string>,
}));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));
vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('@/components/support-dialog', () => ({
    SupportDialog: ({ open }: { open: boolean }) =>
        open ? <div data-testid="support-dialog" /> : null,
}));

vi.mock('@inertiajs/react', async () => {
    const { fakeUseHttp } = await import('@/lib/use-http.fixture');

    return {
        useHttp: fakeUseHttp(mocks.post),
        router: { post: mocks.routerPost },
        usePage: () => ({
            props: { auth: { user: { id: 'user-1' } }, errors: mocks.errors },
        }),
        Link: ({
            children,
            href,
            ...props
        }: React.AnchorHTMLAttributes<HTMLAnchorElement>) => (
            <a href={href} {...props}>
                {children}
            </a>
        ),
    };
});

const COUPON: CancellationOffer = {
    offer: 'retention_coupon',
    percentOff: 30,
    months: 3,
    savedAmount: 8.09,
};
const YEARLY: CancellationOffer = {
    offer: 'switch_to_yearly',
    price: 53.94,
    savings: 53.94,
};

function answerWith(
    offers: CancellationOffer[],
    overrides: Partial<CancellationFlowAnswer> = {},
): CancellationFlowAnswer {
    return {
        id: 'answer-1',
        offers,
        endsAt: '2026-11-25T10:00:00+00:00',
        onTrial: false,
        ...overrides,
    };
}

function renderFlow(onOpenChange = vi.fn()) {
    render(
        <CancelSubscriptionFlow
            open
            onOpenChange={onOpenChange}
            onTrial={false}
            currency="EUR"
            locale="en"
        />,
    );

    return onOpenChange;
}

async function answer(reason: CancellationReason, details?: string) {
    fireEvent.click(screen.getByTestId(`cancel-flow-reason-${reason}`));

    if (details !== undefined) {
        fireEvent.change(screen.getByTestId('cancel-flow-details'), {
            target: { value: details },
        });
    }

    await act(async () => {
        fireEvent.click(screen.getByTestId('cancel-flow-continue'));
    });
}

function eventsNamed(name: string): unknown[] {
    return mocks.captureEvent.mock.calls
        .filter(([event]) => event === name)
        .map(([, properties]) => properties);
}

describe('CancelSubscriptionFlow', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.errors = {};
        mocks.routerPost.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onSuccess?: (page: unknown) => void },
            ) => options.onSuccess?.({ props: { flash: {} } }),
        );
    });

    it('asks one question, with every reason as a choice', () => {
        renderFlow();

        expect(screen.getByText('Help us improve')).toBeInTheDocument();
        expect(screen.getAllByRole('radio')).toHaveLength(9);
        expect(screen.getByTestId('cancel-flow-continue')).toBeDisabled();
    });

    it('sends the reason and the words, and reports only the reason', async () => {
        mocks.post.mockResolvedValue(answerWith([]));
        renderFlow();

        await answer('other', 'I only track cash');

        expect(mocks.post).toHaveBeenCalledWith(
            '/settings/billing/cancellation',
            {
                reason: 'other',
                details: 'I only track cash',
            },
        );
        expect(eventsNamed('cancel_flow_reason_selected')).toEqual([
            { on_trial: false, reason: 'other' },
        ]);
    });

    it('asks what is missing for a missing feature', () => {
        renderFlow();

        fireEvent.click(
            screen.getByTestId('cancel-flow-reason-missing_feature'),
        );

        expect(
            screen.getByPlaceholderText("What's missing? (optional)"),
        ).toBeInTheDocument();
    });

    it.each<[CancellationReason, CancellationOffer, RegExp]>([
        ['too_expensive', COUPON, /30% off for the next 3 months/],
        ['missing_feature', { offer: 'roadmap' }, /we read every answer/],
        [
            'bank_connection_problems',
            { offer: 'support' },
            /help you sort it out/,
        ],
        ['too_complex', { offer: 'support' }, /help you sort it out/],
    ])(
        'shows the offer the server matched to %s, with a way past it',
        async (reason, offer, title) => {
            mocks.post.mockResolvedValue(answerWith([offer]));
            renderFlow();

            await answer(reason);

            expect(screen.getByText(title)).toBeInTheDocument();
            expect(
                screen.getByText('No thanks, continue cancelling'),
            ).toBeInTheDocument();
            expect(eventsNamed('cancel_flow_offer_shown')).toEqual([
                { on_trial: false, offer: offer.offer },
            ]);
        },
    );

    it.each<CancellationReason>([
        'not_using_enough',
        'just_trying',
        'switching_app',
        'dont_trust_bank',
        'other',
    ])('goes straight to the confirmation for %s', async (reason) => {
        mocks.post.mockResolvedValue(answerWith([]));
        renderFlow();

        await answer(reason);

        expect(
            screen.getByText('Your plan stays on until Nov 25, 2026'),
        ).toBeInTheDocument();
        expect(eventsNamed('cancel_flow_offer_shown')).toEqual([]);
    });

    it('quotes the saving of the discount in the currency', async () => {
        mocks.post.mockResolvedValue(answerWith([COUPON]));
        renderFlow();

        await answer('too_expensive');

        expect(screen.getByText(/Stay and save €8\.09/)).toBeInTheDocument();
    });

    it('shows the yearly fallback after the discount is declined, then the confirmation', async () => {
        mocks.post.mockResolvedValue(answerWith([COUPON, YEARLY]));
        renderFlow();

        await answer('too_expensive');
        fireEvent.click(screen.getByTestId('cancel-flow-decline'));

        expect(
            screen.getByText('Pay yearly and save €53.94'),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('cancel-flow-decline'));

        expect(screen.getByTestId('cancel-flow-confirm')).toBeInTheDocument();
        expect(eventsNamed('cancel_flow_offer_declined')).toEqual([
            { on_trial: false, offer: 'retention_coupon' },
            { on_trial: false, offer: 'switch_to_yearly' },
        ]);
    });

    it.each<[CancellationOffer, string]>([
        [COUPON, '/settings/billing/cancellation/answer-1/retention-coupon'],
        [YEARLY, '/settings/billing/cancellation/answer-1/switch-to-yearly'],
    ])('takes a saving offer and closes', async (offer, url) => {
        mocks.post.mockResolvedValue(answerWith([offer]));
        const onOpenChange = renderFlow();

        await answer('too_expensive');
        fireEvent.click(screen.getByTestId('cancel-flow-accept'));

        expect(mocks.routerPost).toHaveBeenCalledWith(
            url,
            {},
            expect.anything(),
        );
        expect(eventsNamed('cancel_flow_offer_accepted')).toEqual([
            { on_trial: false, offer: offer.offer },
        ]);
        expect(onOpenChange).toHaveBeenCalledWith(false);
        expect(eventsNamed('cancel_flow_abandoned')).toEqual([]);
    });

    it('counts a discount as accepted only once the server applied it', async () => {
        mocks.routerPost.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onHttpException?: () => boolean },
            ) => options.onHttpException?.(),
        );
        mocks.post.mockResolvedValue(answerWith([COUPON]));
        const onOpenChange = renderFlow();

        await answer('too_expensive');
        fireEvent.click(screen.getByTestId('cancel-flow-accept'));

        expect(eventsNamed('cancel_flow_offer_accepted')).toEqual([]);
        expect(onOpenChange).not.toHaveBeenCalledWith(false);
    });

    it('reports the reason only once the answer is stored', async () => {
        mocks.post.mockRejectedValue(new Error('429'));
        renderFlow();

        await answer('too_expensive');

        expect(eventsNamed('cancel_flow_reason_selected')).toEqual([]);
        expect(screen.getByTestId('cancel-flow-continue')).toBeInTheDocument();
    });

    it('opens the support dialog for a help offer', async () => {
        mocks.post.mockResolvedValue(answerWith([{ offer: 'support' }]));
        renderFlow();

        await answer('bank_connection_problems');
        fireEvent.click(screen.getByTestId('cancel-flow-accept'));

        expect(screen.getByTestId('support-dialog')).toBeInTheDocument();
        expect(eventsNamed('cancel_flow_offer_accepted')).toEqual([
            { on_trial: false, offer: 'support', action: 'contact' },
        ]);
    });

    it('explains what stops and what stays, then cancels', async () => {
        mocks.post.mockResolvedValue(answerWith([]));
        const onOpenChange = renderFlow();

        await answer('just_trying');

        expect(
            screen.getByText(/bank sync and AI categorization stop/),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/stay yours, on the free plan/),
        ).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('cancel-flow-confirm'));

        expect(mocks.routerPost).toHaveBeenCalledWith(
            '/settings/billing/cancellation/answer-1/cancel',
            {},
            expect.anything(),
        );
        expect(eventsNamed('subscription_canceled_in_app')).toEqual([
            { on_trial: false, reason: 'just_trying' },
        ]);
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('tells a trial it will not be charged at all', async () => {
        mocks.post.mockResolvedValue(answerWith([], { onTrial: true }));
        renderFlow();

        await answer('just_trying');

        expect(
            screen.getByText(/^You won't be charged\. Nothing stops/),
        ).toBeInTheDocument();
    });

    it('counts closing before the end as abandoning, at the step it happened', async () => {
        mocks.post.mockResolvedValue(answerWith([COUPON]));
        const onOpenChange = renderFlow();

        await answer('too_expensive');
        fireEvent.keyDown(document.activeElement ?? document.body, {
            key: 'Escape',
        });

        expect(eventsNamed('cancel_flow_abandoned')).toEqual([
            { on_trial: false, step: 'offer' },
        ]);
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('keeps the plan from the first step', () => {
        const onOpenChange = renderFlow();

        fireEvent.click(screen.getByText('Keep my plan'));

        expect(eventsNamed('cancel_flow_abandoned')).toEqual([
            { on_trial: false, step: 'reason' },
        ]);
        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    it('shows an error the server sent back', () => {
        mocks.errors = {
            cancellation: 'This offer is no longer available on your plan.',
        };
        renderFlow();

        expect(
            screen.getByText('This offer is no longer available on your plan.'),
        ).toBeInTheDocument();
    });
});
