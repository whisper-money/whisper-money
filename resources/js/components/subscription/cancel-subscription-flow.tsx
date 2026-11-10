import {
    OfferStep,
    type CancellationOffer,
} from '@/components/subscription/cancel-flow-offer-step';
import { SupportDialog } from '@/components/support-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { captureEvent } from '@/lib/posthog';
import {
    cancel as cancelRoute,
    retentionCoupon as retentionCouponRoute,
    store as storeRoute,
    switchToYearly as switchToYearlyRoute,
} from '@/routes/settings/billing/cancellation';
import { type SharedData } from '@/types';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, useHttp, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

export type { CancellationOffer };

/**
 * `App\Enums\CancellationReason`, in the order the flow lists them: the most
 * expected first, "Other" last. Keep the two in step by hand: the server
 * refuses a reason it does not know, and a reason missing here is never shown.
 */
const REASON_LABELS = {
    not_using_enough: () => __("I'm not using it enough"),
    just_trying: () => __('I was just trying it out'),
    missing_feature: () => __("It's missing a feature I need"),
    switching_app: () => __("I'm switching to another app"),
    bank_connection_problems: () =>
        __("My bank isn't supported or the connection fails"),
    too_expensive: () => __("It's too expensive"),
    too_complex: () => __("It's too complicated"),
    dont_trust_bank: () => __("I don't trust connecting my bank"),
    other: () => __('Something else'),
} satisfies Record<string, () => string>;

export type CancellationReason = keyof typeof REASON_LABELS;

const REASONS = Object.keys(REASON_LABELS) as CancellationReason[];

/** Same cap as the server's, so the textarea stops where validation would. */
const DETAILS_MAX_LENGTH = 1000;

export interface CancellationFlowAnswer {
    id: string;
    offers: CancellationOffer[];
    /** The day the plan would stop; null when Stripe could not say. */
    endsAt: string | null;
    onTrial: boolean;
}

type Step =
    | { name: 'reason' }
    | { name: 'offer'; index: number }
    | { name: 'confirm' };

/**
 * The in-app way to cancel a running plan: one question, one offer matched to
 * the answer (and at most one fallback), then a cancellation at the end of the
 * period. "Help us improve", never guilt: every step has a plain way on to
 * the cancellation, and closing the dialog keeps the plan as it is.
 *
 * The free text never leaves for analytics: PostHog gets the reason and the
 * offers, the words stay in our own database.
 */
export function CancelSubscriptionFlow({
    open,
    onOpenChange,
    onTrial,
    currency,
    locale,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onTrial: boolean;
    currency: string;
    locale: string;
}) {
    const { auth, errors } = usePage<
        SharedData & { errors: Record<string, string> }
    >().props;
    const http = useHttp<
        { reason: CancellationReason | ''; details: string },
        CancellationFlowAnswer
    >({ reason: '', details: '' });
    const [answer, setAnswer] = useState<CancellationFlowAnswer | null>(null);
    const [step, setStep] = useState<Step>({ name: 'reason' });
    const [processing, setProcessing] = useState(false);
    const [supportOpen, setSupportOpen] = useState(false);
    // Set once the flow ends one way or another (an offer taken, the plan
    // cancelled), so closing the dialog afterwards is not counted as walking
    // away from it.
    const ended = useRef(false);

    const analytics = { on_trial: onTrial };

    const showOffer = (index: number, offers: CancellationOffer[]) => {
        const offer = offers[index];

        if (offer === undefined) {
            setStep({ name: 'confirm' });

            return;
        }

        captureEvent('cancel_flow_offer_shown', {
            ...analytics,
            offer: offer.offer,
        });
        setStep({ name: 'offer', index });
    };

    const submitReason = () => {
        if (http.data.reason === '') {
            return;
        }

        const reason = http.data.reason;

        http.post(storeRoute.url())
            .then((response) => {
                captureEvent('cancel_flow_reason_selected', {
                    ...analytics,
                    reason,
                });
                setAnswer(response);
                showOffer(0, response.offers);
            })
            .catch(() => toast.error(__('Something went wrong.')));
    };

    const close = () => {
        // A request in flight may still save or cancel the plan: closing now
        // would count that as walking away, and hide its outcome.
        if (processing || http.processing) {
            return;
        }

        if (!ended.current) {
            captureEvent('cancel_flow_abandoned', {
                ...analytics,
                step: step.name,
            });
        }

        onOpenChange(false);
    };

    const end = () => {
        ended.current = true;
        onOpenChange(false);
    };

    // Anything but an Inertia page (a 409 for a flow that already ended, a
    // 429) would otherwise replace the page with Inertia's error modal.
    const reportFailure = (): false => {
        toast.error(__('Something went wrong.'));

        return false;
    };

    const post = (url: string, onSuccess: () => void) => {
        setProcessing(true);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const { flash } = page.props as unknown as SharedData;

                    onSuccess();

                    if (flash?.success) {
                        toast.success(flash.success);
                    }
                },
                onHttpException: reportFailure,
                onNetworkError: reportFailure,
                onFinish: () => setProcessing(false),
            },
        );
    };

    /**
     * `action` tells apart the two ways a help offer can be taken: the
     * support dialog, or the link to the bank connections (which navigates
     * away by itself).
     */
    const acceptOffer = (
        offer: CancellationOffer,
        action?: 'contact' | 'connections',
    ) => {
        if (answer === null) {
            return;
        }

        const reportAccepted = () =>
            captureEvent('cancel_flow_offer_accepted', {
                ...analytics,
                offer: offer.offer,
                ...(action ? { action } : {}),
            });

        // The offers that keep the plan count once the server has applied
        // them; the help offers as soon as they are followed.
        const takeSavingOffer = (url: string) =>
            post(url, () => {
                reportAccepted();
                end();
            });

        switch (offer.offer) {
            case 'retention_coupon':
                return takeSavingOffer(retentionCouponRoute.url(answer.id));
            case 'switch_to_yearly':
                return takeSavingOffer(switchToYearlyRoute.url(answer.id));
            case 'support':
                reportAccepted();
                end();

                return setSupportOpen(action === 'contact');
            case 'roadmap':
                return reportAccepted();
        }
    };

    const declineOffer = (index: number) => {
        if (answer === null) {
            return;
        }

        captureEvent('cancel_flow_offer_declined', {
            ...analytics,
            offer: answer.offers[index].offer,
        });
        showOffer(index + 1, answer.offers);
    };

    const confirm = () => {
        if (answer === null) {
            return;
        }

        post(cancelRoute.url(answer.id), () => {
            captureEvent('subscription_canceled_in_app', {
                ...analytics,
                reason: http.data.reason,
            });
            end();
        });
    };

    const content =
        step.name === 'reason' ? (
            <ReasonStep
                reason={http.data.reason}
                details={http.data.details}
                onReason={(reason) => http.setData('reason', reason)}
                onDetails={(details) => http.setData('details', details)}
                processing={http.processing}
                onContinue={submitReason}
                onKeep={close}
            />
        ) : step.name === 'offer' && answer !== null ? (
            <OfferStep
                offer={answer.offers[step.index]}
                onTrial={answer.onTrial}
                endsAt={answer.endsAt}
                currency={currency}
                locale={locale}
                processing={processing}
                onAccept={acceptOffer}
                onDecline={() => declineOffer(step.index)}
            />
        ) : answer !== null ? (
            <ConfirmStep
                answer={answer}
                locale={locale}
                processing={processing}
                onConfirm={confirm}
                onKeep={close}
            />
        ) : null;

    return (
        <>
            <Dialog
                open={open}
                onOpenChange={(next) => (next ? onOpenChange(true) : close())}
            >
                <DialogContent
                    className="max-h-[90dvh] overflow-y-auto sm:max-w-md"
                    data-testid="cancel-flow"
                >
                    {content}
                    {errors.cancellation && (
                        <p className="text-sm text-destructive">
                            {errors.cancellation}
                        </p>
                    )}
                </DialogContent>
            </Dialog>
            {auth?.user && (
                <SupportDialog
                    open={supportOpen}
                    onOpenChange={setSupportOpen}
                    user={auth.user}
                />
            )}
        </>
    );
}

function ReasonStep({
    reason,
    details,
    onReason,
    onDetails,
    processing,
    onContinue,
    onKeep,
}: {
    reason: CancellationReason | '';
    details: string;
    onReason: (reason: CancellationReason) => void;
    onDetails: (details: string) => void;
    processing: boolean;
    onContinue: () => void;
    onKeep: () => void;
}) {
    const title = __('Help us improve');
    const detailsLabel =
        reason === 'missing_feature'
            ? __("What's missing? (optional)")
            : __('Anything else you want to tell us? (optional)');

    return (
        <>
            <DialogHeader>
                <DialogTitle className="pr-6">{title}</DialogTitle>
                <DialogDescription>
                    {__(
                        "Why are you cancelling? One answer is enough, and it won't stop you from cancelling.",
                    )}
                </DialogDescription>
            </DialogHeader>
            <div className="flex flex-col gap-3">
                <RadioGroup
                    value={reason}
                    onValueChange={(value) =>
                        onReason(value as CancellationReason)
                    }
                    aria-label={__('Why are you cancelling?')}
                    className="gap-2"
                >
                    {REASONS.map((option) => (
                        <label
                            key={option}
                            className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-4 py-2.5 text-sm transition-colors hover:bg-accent has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-accent dark:hover:bg-accent/50 dark:has-[[data-state=checked]]:bg-accent/50"
                        >
                            <RadioGroupItem
                                value={option}
                                data-testid={`cancel-flow-reason-${option}`}
                            />
                            <span>{REASON_LABELS[option]()}</span>
                        </label>
                    ))}
                </RadioGroup>

                {reason !== '' && (
                    <Textarea
                        value={details}
                        onChange={(event) => onDetails(event.target.value)}
                        placeholder={detailsLabel}
                        aria-label={detailsLabel}
                        maxLength={DETAILS_MAX_LENGTH}
                        rows={3}
                        data-testid="cancel-flow-details"
                    />
                )}
            </div>
            <DialogFooter>
                <Button variant="ghost" onClick={onKeep}>
                    {__('Keep my plan')}
                </Button>
                <Button
                    onClick={onContinue}
                    disabled={reason === '' || processing}
                    data-testid="cancel-flow-continue"
                >
                    {processing && <Spinner />}
                    {__('Continue')}
                </Button>
            </DialogFooter>
        </>
    );
}

/**
 * The last word before cancelling: the day the plan stops, what stops with it, and
 * that everything the user put in stays. Nothing ends today.
 */
function ConfirmStep({
    answer,
    locale,
    processing,
    onConfirm,
    onKeep,
}: {
    answer: CancellationFlowAnswer;
    locale: string;
    processing: boolean;
    onConfirm: () => void;
    onKeep: () => void;
}) {
    const date = answer.endsAt ? formatDateMedium(answer.endsAt, locale) : null;

    return (
        <>
            <DialogHeader>
                <DialogTitle className="pr-6">
                    {date
                        ? __('Your plan stays on until :date', { date })
                        : __(
                              'Your plan stays on until the end of your billing period',
                          )}
                </DialogTitle>
                <DialogDescription>
                    {answer.onTrial
                        ? __(
                              "You won't be charged. Nothing stops today: you keep every Standard feature until then.",
                          )
                        : __(
                              "You won't be charged again. Nothing stops today: you keep every Standard feature until then.",
                          )}
                </DialogDescription>
            </DialogHeader>
            <ul className="flex flex-col gap-2 rounded-lg border bg-muted/40 p-4 text-sm">
                <li>
                    {__('After that, bank sync and AI categorization stop.')}
                </li>
                <li>
                    {__(
                        'Your accounts, transactions, categories and rules stay yours, on the free plan.',
                    )}
                </li>
                <li>
                    {__(
                        'Changed your mind? Keep your plan from this page any time before it ends.',
                    )}
                </li>
            </ul>
            <DialogFooter>
                <Button variant="ghost" onClick={onKeep} disabled={processing}>
                    {__('Keep my plan')}
                </Button>
                <Button
                    variant="destructive"
                    onClick={onConfirm}
                    disabled={processing}
                    data-testid="cancel-flow-confirm"
                >
                    {processing && <Spinner />}
                    {__('Cancel subscription')}
                </Button>
            </DialogFooter>
        </>
    );
}
