import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerFooter,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useIsMobile } from '@/hooks/use-mobile';
import { captureEvent } from '@/lib/posthog';
import { survey as surveyRoute } from '@/routes/subscribe/free-plan';
import { __ } from '@/utils/i18n';
import { useHttp } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';

/**
 * Which of the paywall's three screens the user is on.
 *
 * `soft` is the only one a signup reaching this today can land on: a bank and
 * the AI both now require a plan before they can be switched on, so nobody
 * finishes onboarding owing us anything. The other two are the users who got
 * into that state under the old rules, and the users who paid and stopped.
 */
export type PaywallGate = 'former-subscriber' | 'soft' | 'hard';

/**
 * `App\Enums\FreePlanSurveyReason`, in the order the survey lists them. A
 * record rather than a list, so a reason added on one side without its label
 * fails to type-check.
 */
const REASON_LABELS = {
    too_expensive: () => __("It's too expensive for me"),
    still_trying: () => __("I'm still trying the app out"),
    no_need_for_sync_or_ai: () => __("I don't need bank sync or the AI"),
    dont_trust_bank_connection: () => __("I don't trust connecting my bank"),
    use_too_little: () => __('I use it too little to pay'),
    other: () => __('Other reason'),
} satisfies Record<string, () => string>;

type FreePlanSurveyReason = keyof typeof REASON_LABELS;

const REASONS = Object.keys(REASON_LABELS) as FreePlanSurveyReason[];

/** Same cap as the server's, so the textarea stops where validation would. */
const DETAILS_MAX_LENGTH = 1000;

/**
 * The survey in front of a way out: `ask()` opens it the first time and goes
 * straight on after that, so a way out that has to be retried — a failed
 * disconnect, a visit that did not land — does not ask the same question
 * twice. The survey closes as soon as it is answered or skipped.
 */
export function useFreePlanSurvey(onContinue: () => void) {
    const [open, setOpen] = useState(false);
    const [asked, setAsked] = useState(false);

    const ask = () => {
        if (asked) {
            onContinue();

            return;
        }

        setAsked(true);
        setOpen(true);
    };

    return {
        ask,
        surveyProps: {
            open,
            onContinue: () => {
                setOpen(false);
                onContinue();
            },
        },
    };
}

/**
 * "What's keeping you on free?", asked on the way out of the paywall: a bottom
 * sheet on the phone, a dialog on a desktop.
 *
 * It sits in front of a choice the user has already made, so it can never undo
 * it. Every way out — sending, skipping, the X, Escape, a tap outside — calls
 * `onContinue`, and a response that fails to save continues all the same: the
 * answer is for us, the free plan is theirs either way.
 *
 * The free text never leaves for analytics: PostHog gets the reason and the
 * gate, the words stay in our own database.
 */
export function FreePlanSurvey({
    open,
    gate,
    onContinue,
    description = __(
        'A quick answer helps us improve. You stay on the free plan either way.',
    ),
}: {
    open: boolean;
    gate: PaywallGate;
    onContinue: () => void;
    /** What happens next whatever the answer, under the question. */
    description?: string;
}) {
    const isMobile = useIsMobile();
    // No gate in the answer: the server reads it off the user. The prop only
    // labels the analytics events below.
    const http = useHttp<{
        reason: FreePlanSurveyReason | '';
        details: string;
    }>({ reason: '', details: '' });

    // Set by the first way out the user takes, so nothing after it counts: a
    // second click on Send while the next page loads would store the answer
    // twice, and Escape during the request would continue twice. The ref
    // guards and the state disables the buttons: two clicks can land before
    // React re-renders with the buttons disabled.
    const chosen = useRef(false);
    const [hasChosen, setHasChosen] = useState(false);

    const choose = (): boolean => {
        if (chosen.current) {
            return false;
        }

        chosen.current = true;
        setHasChosen(true);

        return true;
    };

    const skip = () => {
        if (!choose()) {
            return;
        }

        captureEvent('paywall_free_plan_survey_skipped', { gate });
        onContinue();
    };

    const send = () => {
        if (http.data.reason === '' || !choose()) {
            return;
        }

        captureEvent('paywall_free_plan_survey_submitted', {
            reason: http.data.reason,
            gate,
        });

        // Thanked only for an answer that was kept; one that was not still
        // lets the user through.
        http.post(surveyRoute.url())
            .then(() => toast.success(__('Thanks for telling us')))
            .catch(() => undefined)
            .finally(onContinue);
    };

    const onOpenChange = (next: boolean) => {
        if (!next) {
            skip();
        }
    };

    const title = __("What's keeping you on free?");

    const body = (
        <div className="flex flex-col gap-3">
            <RadioGroup
                value={http.data.reason}
                onValueChange={(value) =>
                    http.setData('reason', value as FreePlanSurveyReason)
                }
                aria-label={title}
                className="gap-2"
            >
                {REASONS.map((reason) => (
                    <label
                        key={reason}
                        className="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border px-4 py-3 text-sm transition-colors hover:bg-accent has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-accent dark:hover:bg-accent/50 dark:has-[[data-state=checked]]:bg-accent/50"
                    >
                        <RadioGroupItem
                            value={reason}
                            data-testid={`free-plan-survey-reason-${reason}`}
                        />
                        <span>{REASON_LABELS[reason]()}</span>
                    </label>
                ))}
            </RadioGroup>

            {http.data.reason === 'other' && (
                <Textarea
                    value={http.data.details}
                    onChange={(event) =>
                        http.setData('details', event.target.value)
                    }
                    placeholder={__('Tell us a bit more (optional)')}
                    aria-label={__('Tell us a bit more (optional)')}
                    maxLength={DETAILS_MAX_LENGTH}
                    rows={3}
                    // On the phone the keyboard would cover the buttons.
                    autoFocus={!isMobile}
                    data-testid="free-plan-survey-details"
                />
            )}
        </div>
    );

    const sendButton = (
        <Button
            onClick={send}
            disabled={http.data.reason === '' || hasChosen}
            data-testid="free-plan-survey-send"
        >
            {http.processing && <Spinner />}
            {__('Send and stay free')}
        </Button>
    );

    const skipButton = (
        <Button
            variant="ghost"
            onClick={skip}
            disabled={hasChosen}
            data-testid="free-plan-survey-skip"
        >
            {isMobile ? __('Skip') : __('Not now')}
        </Button>
    );

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerContent data-testid="free-plan-survey">
                    <DrawerHeader>
                        <DrawerTitle>{title}</DrawerTitle>
                        <DrawerDescription>{description}</DrawerDescription>
                    </DrawerHeader>
                    <div className="overflow-y-auto px-4">{body}</div>
                    <DrawerFooter>
                        {sendButton}
                        {skipButton}
                    </DrawerFooter>
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="sm:max-w-md"
                data-testid="free-plan-survey"
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {body}
                <DialogFooter>
                    {skipButton}
                    {sendButton}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
