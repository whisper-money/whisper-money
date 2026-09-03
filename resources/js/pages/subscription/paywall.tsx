import { StepButton } from '@/components/onboarding/step-button';
import {
    StepChevron,
    StepList,
    StepRow,
    StepSectionLabel,
} from '@/components/onboarding/step-list';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
import { PlanPicker, planTerms } from '@/components/subscription/plan-picker';
import { SupportDialog } from '@/components/support-dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { captureEvent } from '@/lib/posthog';
import { dashboard } from '@/routes';
import { index as connectionsIndex } from '@/routes/settings/connections';
import { checkout, freePlan } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { Landmark, LifeBuoy, Plug, Sparkles } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

interface PaywallStats {
    accountsCount: number;
    transactionsCount: number;
    categoriesCount: number;
}

interface PaywallPageProps extends SharedData {
    stats: PaywallStats;
    canUseFreePlan: boolean;
    /**
     * Whether the delay after onboarding has run out, so the gates that have
     * something to disconnect may offer the free plan. Decided on the server —
     * the cut-off time never reaches the browser, because a user who is shown a
     * countdown is being told to wait rather than to choose a plan.
     */
    canEscapeToFreePlan: boolean;
    canManageConnectionsForFreePlan: boolean;
}

/**
 * Which of the three screens the user gets. The two server flags are mutually
 * exclusive today, but reading them at seven separate sites left the incoherent
 * combination renderable; collapsing them once makes it unrepresentable.
 */
type PaywallGate = 'former-subscriber' | 'soft' | 'hard';

export default function Paywall() {
    const {
        auth,
        locale,
        pricing,
        stats,
        canUseFreePlan,
        canEscapeToFreePlan,
        canManageConnectionsForFreePlan,
    } = usePage<PaywallPageProps>().props;

    const [selectedPlan, setSelectedPlan] = useState(pricing.defaultPlan);
    const [supportOpen, setSupportOpen] = useState(false);
    const [freePlanOpen, setFreePlanOpen] = useState(false);

    const gate: PaywallGate = canManageConnectionsForFreePlan
        ? 'former-subscriber'
        : canUseFreePlan
          ? 'soft'
          : 'hard';

    // This is the highest-leverage screen in the product and it carried no
    // instrumentation at all, so moving the escape hatch to first paint would
    // have shipped unmeasurable. `paywall_seen_at` is stamped on view, so it
    // cannot stand in for the choice.
    useEffect(() => {
        captureEvent('paywall_viewed', { gate });
    }, [gate]);

    if (Object.keys(pricing.plans).length === 0) {
        return null;
    }

    const title =
        gate === 'former-subscriber'
            ? __('Your plan has ended')
            : __('Choose your plan');

    const terms = planTerms(
        pricing.plans[selectedPlan],
        pricing.currency,
        locale,
    );

    const continueFree = () => {
        captureEvent('paywall_free_plan_chosen');
        router.visit(dashboard().url);
    };

    const openFreePlanConfirmation = () => {
        captureEvent('paywall_free_plan_confirm_opened', { gate });
        setFreePlanOpen(true);
    };

    return (
        <SubscriptionLayout>
            <Head title={title} />

            <StepScreen
                title={title}
                description={gateDescription(gate, stats, locale)}
                // The longest screen in this vocabulary: at 1280x720 an inline
                // footer puts the primary action 137px below the fold, and even
                // at 1440x900 it clears by only 43px, so one extra line of copy
                // would bury it. Pinned at every width instead.
                pinFooter
                footer={
                    <>
                        {gate !== 'former-subscriber' && (
                            <StepNote>
                                {__(
                                    '4,000+ people have signed up. We never sell your data.',
                                )}
                            </StepNote>
                        )}

                        {terms && <StepNote emphasis>{terms}</StepNote>}

                        <StepButton
                            text={__('Start a plan')}
                            href={checkout.url({
                                query: { plan: selectedPlan },
                            })}
                        />

                        {/* On the soft gate the way out is here from the
                            first paint: there is nothing to disconnect and
                            nothing to warn about, so a timed reveal would only
                            hide a choice the user is entitled to make. The
                            other two gates are asking the user to give up the
                            bank they just connected, which is a consequence
                            that has to be explained rather than offered in
                            passing — so the door waits out the delay after
                            onboarding, leaving room to choose a plan first.
                            The wait itself is never named on screen: the button
                            is simply there or it is not. */}
                        {gate === 'soft' ? (
                            <StepButton
                                text={__('Continue with the free plan')}
                                variant="ghost"
                                onClick={continueFree}
                            />
                        ) : (
                            <>
                                {canEscapeToFreePlan && (
                                    <StepButton
                                        text={__('Continue with the free plan')}
                                        variant="ghost"
                                        onClick={openFreePlanConfirmation}
                                    />
                                )}

                                <StepButton
                                    text={__('Need help?')}
                                    variant="ghost"
                                    icon={LifeBuoy}
                                    onClick={() => setSupportOpen(true)}
                                />
                            </>
                        )}
                    </>
                }
            >
                {gate !== 'former-subscriber' && <PaidFeatures />}

                <PlanPicker
                    plans={pricing.plans}
                    currency={pricing.currency}
                    selectedPlan={selectedPlan}
                    onSelect={setSelectedPlan}
                />

                {gate === 'former-subscriber' && (
                    <div>
                        <StepSectionLabel>{__('Or go free')}</StepSectionLabel>
                        <StepList>
                            <StepRow
                                icon={Landmark}
                                title={__('Disconnect your banks')}
                                description={__(
                                    'In Settings, under Connections.',
                                )}
                                meta={__(
                                    'Your accounts stop updating. Every transaction already imported stays.',
                                )}
                                trailing={<StepChevron />}
                                onClick={() =>
                                    router.visit(connectionsIndex().url)
                                }
                            />
                        </StepList>
                    </div>
                )}
            </StepScreen>

            {gate !== 'soft' && (
                <SupportDialog
                    open={supportOpen}
                    onOpenChange={setSupportOpen}
                    user={auth.user}
                />
            )}

            {gate !== 'soft' && canEscapeToFreePlan && (
                <FreePlanDialog
                    open={freePlanOpen}
                    onOpenChange={setFreePlanOpen}
                    onConfirm={() =>
                        captureEvent('paywall_free_plan_confirmed', { gate })
                    }
                />
            )}
        </SubscriptionLayout>
    );
}

/**
 * The confirmation behind the way down to the free plan. It names the three
 * paid features that switch off — the same three the screen sells above — and
 * says outright that the imported data stays, because "we disconnect your
 * banks" reads as "we delete my transactions" to most people.
 */
function FreePlanDialog({
    open,
    onOpenChange,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Fired as the request goes out, so the choice can be measured. */
    onConfirm: () => void;
}) {
    const [isLeaving, setIsLeaving] = useState(false);

    const chooseFreePlan = () => {
        onConfirm();
        setIsLeaving(true);

        // The server redirects to the dashboard on success, so there is nothing
        // to do here but report a refusal — a rejected request that left the
        // dialog sitting there would read as "it worked".
        router.post(
            freePlan.url(),
            {},
            {
                onError: () =>
                    toast.error(
                        __(
                            'We could not move you to the free plan. Try again.',
                        ),
                    ),
                onFinish: () => setIsLeaving(false),
            },
        );
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {__('Continue with the free plan?')}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {__(
                            'The free plan does not include the paid features, so we switch all three off:',
                        )}
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <ul className="list-disc space-y-2 pl-5 text-sm text-muted-foreground">
                    <li>
                        {__(
                            'Your connected banks are disconnected and stop syncing.',
                        )}
                    </li>
                    <li>{__('AI suggestions are switched off.')}</li>
                    <li>
                        {__('Your AI assistant loses access to your finances.')}
                    </li>
                    <li className="font-medium text-foreground">
                        {__(
                            'Your accounts and every transaction already imported stay. You can start a plan again whenever you want.',
                        )}
                    </li>
                </ul>

                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isLeaving}>
                        {__('Cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={(event) => {
                            // Without this the dialog closes before the request
                            // goes out, taking the pending state with it.
                            event.preventDefault();
                            chooseFreePlan();
                        }}
                        disabled={isLeaving}
                    >
                        {isLeaving
                            ? __('Disconnecting...')
                            : __('Disconnect and continue')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/**
 * What a paid plan actually adds — the three features `App\Enums\PlanFeature`
 * gates. Not the config `features` array, most of which the free plan already
 * does: there is no account or transaction limit anywhere in the app, and the
 * landing page correctly lists "unlimited" as a free-plan property.
 */
function PaidFeatures() {
    return (
        <StepList>
            <StepRow
                icon={Landmark}
                title={__('Connected banks')}
                description={__('Transactions and balances sync themselves.')}
            />
            <StepRow
                icon={Sparkles}
                title={__('AI suggestions')}
                description={__(
                    'Rules learned from how you already categorize.',
                )}
            />
            <StepRow
                icon={Plug}
                title={__('Your AI assistant')}
                description={__('Ask Claude or ChatGPT about your finances.')}
            />
        </StepList>
    );
}

/**
 * Each gate argues the one thing that is true for it. The hard gate names what
 * the user already has here, because that is what is being withheld; the soft
 * gate does not, because nothing is.
 */
function gateDescription(
    gate: PaywallGate,
    stats: PaywallStats,
    locale: string,
): string {
    if (gate === 'former-subscriber') {
        return __(
            'Your connected banks stopped syncing. Start a plan to turn them back on, or disconnect them and keep going for free.',
        );
    }

    if (gate === 'soft') {
        return __(
            'Bank syncing, AI suggestions and the AI assistant need a paid plan. Everything else in Whisper Money stays free.',
        );
    }

    const opening = __('Bank syncing and AI suggestions need a paid plan.');
    const snapshot = snapshotSentence(stats, locale);

    return snapshot ? `${opening} ${snapshot}` : opening;
}

/**
 * The one personal, honest argument on the page: the data the user has already
 * put in. Both counts have to be there for the sentence to read, so it is
 * dropped whole rather than degrading into "0 transactions".
 */
function snapshotSentence(stats: PaywallStats, locale: string): string | null {
    if (stats.accountsCount === 0 || stats.transactionsCount === 0) {
        return null;
    }

    const accounts =
        stats.accountsCount === 1
            ? __('1 account')
            : __(':count accounts', {
                  count: stats.accountsCount.toLocaleString(locale),
              });

    const transactions =
        stats.transactionsCount === 1
            ? __('1 transaction')
            : __(':count transactions', {
                  count: stats.transactionsCount.toLocaleString(locale),
              });

    return __('Your :accounts and :transactions are already here.', {
        accounts,
        transactions,
    });
}
