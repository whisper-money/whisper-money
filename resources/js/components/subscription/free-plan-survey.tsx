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
import { useRef } from 'react';
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

/** `App\Enums\FreePlanSurveyReason`, in the order the survey lists them. */
const REASONS = [
    'too_expensive',
    'still_trying',
    'no_need_for_sync_or_ai',
    'dont_trust_bank_connection',
    'use_too_little',
    'other',
] as const;

type FreePlanSurveyReason = (typeof REASONS)[number];

/** Same cap as the server's, so the textarea stops where validation would. */
const DETAILS_MAX_LENGTH = 1000;

function reasonLabel(reason: FreePlanSurveyReason): string {
    switch (reason) {
        case 'too_expensive':
            return __("It's too expensive for me");
        case 'still_trying':
            return __("I'm still trying the app out");
        case 'no_need_for_sync_or_ai':
            return __("I don't need bank sync or the AI");
        case 'dont_trust_bank_connection':
            return __("I don't trust connecting my bank");
        case 'use_too_little':
            return __('I use it too little to pay');
        case 'other':
            return __('Other reason');
    }
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
}: {
    open: boolean;
    gate: PaywallGate;
    onContinue: () => void;
}) {
    const isMobile = useIsMobile();
    const http = useHttp<{
        reason: FreePlanSurveyReason | '';
        details: string;
        gate: PaywallGate;
    }>({ reason: '', details: '', gate });

    // Escape or a tap outside while the answer is still sending must not
    // continue a second time.
    const hasContinued = useRef(false);

    const continueOnce = () => {
        if (hasContinued.current) {
            return;
        }

        hasContinued.current = true;
        onContinue();
    };

    const skip = () => {
        if (hasContinued.current || http.processing) {
            return;
        }

        captureEvent('paywall_free_plan_survey_skipped', { gate });
        continueOnce();
    };

    const send = () => {
        if (http.data.reason === '' || http.processing) {
            return;
        }

        captureEvent('paywall_free_plan_survey_submitted', {
            reason: http.data.reason,
            gate,
        });

        http.post(surveyRoute.url())
            .catch(() => undefined)
            .then(() => {
                toast.success(__('Thanks for telling us'));
                continueOnce();
            });
    };

    const onOpenChange = (next: boolean) => {
        if (!next) {
            skip();
        }
    };

    const title = __("What's keeping you on free?");
    const description = __(
        'A quick answer helps us improve. You stay on the free plan either way.',
    );

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
                        <span>{reasonLabel(reason)}</span>
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
                    autoFocus
                    data-testid="free-plan-survey-details"
                />
            )}
        </div>
    );

    const sendButton = (
        <Button
            onClick={send}
            disabled={http.data.reason === '' || http.processing}
            data-testid="free-plan-survey-send"
        >
            {http.processing && <Spinner />}
            {__('Send and stay free')}
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
                        <Button
                            variant="ghost"
                            onClick={skip}
                            disabled={http.processing}
                            data-testid="free-plan-survey-skip"
                        >
                            {__('Skip')}
                        </Button>
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
                    <Button
                        variant="ghost"
                        onClick={skip}
                        disabled={http.processing}
                        data-testid="free-plan-survey-skip"
                    >
                        {__('Not now')}
                    </Button>
                    {sendButton}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
