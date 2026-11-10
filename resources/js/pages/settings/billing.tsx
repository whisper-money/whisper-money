import HeadingSmall from '@/components/heading-small';
import { ProBadge } from '@/components/pro-badge';
import { CancelSubscriptionFlow } from '@/components/subscription/cancel-subscription-flow';
import {
    PausedPlanNotice,
    type SubscriptionPause,
} from '@/components/subscription/paused-plan-notice';
import { PlanPicker, planTerms } from '@/components/subscription/plan-picker';
import { checkoutHref } from '@/components/subscription/subscription-offer';
import { UpgradeDialog } from '@/components/subscription/upgrade-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { usePriceTierChoice } from '@/hooks/use-price-tier';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { leavePage } from '@/lib/leave-page';
import { captureEvent } from '@/lib/posthog';
import {
    destroy as revokeConsent,
    store as storeConsent,
} from '@/routes/ai/consent';
import { billing } from '@/routes/settings';
import {
    cancelUnpaid,
    portal,
    resume,
    swapToStandard,
} from '@/routes/settings/billing';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    CalendarClockIcon,
    CheckIcon,
    CreditCardIcon,
    InfinityIcon,
    InfoIcon,
    LandmarkIcon,
    ShieldCheckIcon,
    SparklesIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Manage Plan',
        href: billing().url,
    },
];

const benefits = [
    {
        icon: LandmarkIcon,
        title: __('Connected Bank Accounts'),
        description: __(
            'Automatically sync transactions directly from your bank. No manual imports needed.',
        ),
    },
    {
        icon: InfinityIcon,
        title: __('Unlimited Everything'),
        description: __(
            'No limits on bank accounts, transactions, or categories.',
        ),
    },
    {
        icon: ShieldCheckIcon,
        title: __('Privacy First'),
        description: __(
            'Your data is never sold or shared with advertisers. You are always the owner.',
        ),
    },
    {
        icon: SparklesIcon,
        title: __('Smart Automation'),
        description: __(
            'Automation rules to categorize transactions automatically.',
        ),
    },
    {
        icon: CreditCardIcon,
        title: __('Priority Support'),
        description: __(
            'Get help when you need it with priority email support.',
        ),
    },
];

function BenefitsGrid() {
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {benefits.map((benefit) => (
                <div
                    key={benefit.title}
                    className="flex items-start gap-3 rounded-lg border bg-card p-4"
                >
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-emerald-100 dark:bg-emerald-900/30">
                        <benefit.icon className="size-5 text-emerald-600 dark:text-emerald-400" />
                    </div>
                    <div>
                        <h3 className="font-medium">{benefit.title}</h3>
                        <p className="text-sm text-muted-foreground">
                            {benefit.description}
                        </p>
                    </div>
                </div>
            ))}
        </div>
    );
}

function UpgradeSection({
    defaultPlan,
    currency,
    locale,
}: {
    defaultPlan: string;
    currency: string;
    locale: string;
}) {
    const tierChoice = usePriceTierChoice();
    const [selectedPlan, setSelectedPlan] = useState(defaultPlan);
    const terms = planTerms(tierChoice.plans[selectedPlan], currency, locale);

    return (
        <div className="space-y-6">
            <HeadingSmall
                title={__('Upgrade to Standard Plan')}
                description={__(
                    'Unlock all features and take full control of your finances.',
                )}
            />

            <BenefitsGrid />

            <div className="rounded-lg border bg-card p-5">
                <p className="mb-2 text-sm font-medium">
                    {__('Choose your billing cycle')}
                </p>

                {/* The same picker as the paywall and the upgrade dialog, at
                    the app's own density — 52px rows would read as the
                    onboarding leaking into a settings page. */}
                <PlanPicker
                    plans={tierChoice.plans}
                    currency={currency}
                    selectedPlan={selectedPlan}
                    onSelect={setSelectedPlan}
                    size="compact"
                    tierChoice={tierChoice}
                />

                {terms && <p className="mt-4 text-sm font-medium">{terms}</p>}

                <Button asChild className="mt-4 w-full">
                    <a
                        href={checkoutHref(
                            selectedPlan,
                            defaultPlan,
                            'billing',
                            tierChoice.tier,
                        )}
                    >
                        {__('Start a plan')}
                    </a>
                </Button>
            </div>
        </div>
    );
}

type PlanPrice = Pick<Plan, 'price' | 'billing_period'>;

interface SubscriptionInfo {
    onTrial: boolean;
    trialEndsAt: string | null;
    onGracePeriod: boolean;
    /** When a scheduled cancellation takes effect; null when none is. */
    endsAt: string | null;
    plan: PlanPrice | null;
    /**
     * The standard price a subscriber on the latam price can move up to, to
     * get bank connections. Null for everyone else.
     */
    standardUpgrade: PlanPrice | null;
    /**
     * Whether the in-app cancel flow can run: a running plan, not already set
     * to cancel nor waiting on a payment.
     */
    canCancel: boolean;
    /** A pause running or still to come; null when none is set. */
    pause: SubscriptionPause | null;
}

function standardUpgradeTerms(
    upgrade: PlanPrice,
    onTrial: boolean,
    total: string,
): string {
    const isYearly = upgrade.billing_period === 'year';

    if (onTrial) {
        return isYearly
            ? __(
                  'Your plan has no bank connection. The standard plan adds it for :total a year, charged when your trial ends.',
                  { total },
              )
            : __(
                  'Your plan has no bank connection. The standard plan adds it for :total a month, charged when your trial ends.',
                  { total },
              );
    }

    return isYearly
        ? __(
              'Your plan has no bank connection. The standard plan adds it for :total a year; you only pay the difference for the rest of this period, today.',
              { total },
          )
        : __(
              'Your plan has no bank connection. The standard plan adds it for :total a month; you only pay the difference for the rest of this period, today.',
              { total },
          );
}

/**
 * The way off the latam price, for a subscriber who wants their bank
 * connected: the standard price of the same period, with the difference for
 * what is left of it charged now.
 */
function StandardUpgradeCard({
    upgrade,
    onTrial,
    currency,
    locale,
}: {
    upgrade: PlanPrice;
    /** Nothing is charged during a trial, so there is no difference to pay today. */
    onTrial: boolean;
    currency: string;
    locale: string;
}) {
    const [processing, setProcessing] = useState(false);
    const total = formatCurrency(upgrade.price * 100, currency, locale);

    const submit = () => {
        setProcessing(true);
        router.post(
            swapToStandard.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const { flash } = page.props as unknown as SharedData;

                    if (flash?.success) {
                        toast.success(flash.success);
                    }
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <div
            className="rounded-lg border bg-card p-5"
            data-testid="standard-upgrade"
        >
            <div className="flex items-start gap-3">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-emerald-100 dark:bg-emerald-900/30">
                    <LandmarkIcon className="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div>
                    <h4 className="font-medium">
                        {__('Connect your bank with the standard plan')}
                    </h4>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {standardUpgradeTerms(upgrade, onTrial, total)}
                    </p>
                </div>
            </div>
            <Button
                className="mt-4"
                disabled={processing}
                onClick={submit}
                data-testid="swap-to-standard"
            >
                {__('Switch to the standard plan')}
            </Button>
        </div>
    );
}

function PlanStatus({
    subscription,
    defaultPlan,
    currency,
    locale,
}: {
    subscription?: SubscriptionInfo | null;
    defaultPlan: PlanPrice | undefined;
    currency: string;
    locale: string;
}) {
    const formatPrice = (plan: PlanPrice | null | undefined) =>
        plan
            ? `${formatCurrency(plan.price * 100, currency, locale)}/${plan.billing_period}`
            : null;

    if (!subscription?.onTrial || !subscription.trialEndsAt) {
        // The default plan stands in when the subscriber's own could not be
        // matched (a seeded subscription, Stripe unreachable), as it always has.
        const price = formatPrice(subscription?.plan ?? defaultPlan);

        return (
            <div className="flex items-center gap-2">
                <CheckIcon className="size-5 text-emerald-500" />
                <span className="font-medium">
                    {__('Standard plan active')}
                </span>
                {price && (
                    <span className="text-muted-foreground">{`\u2014 ${price}`}</span>
                )}
            </div>
        );
    }

    const date = formatDateMedium(subscription.trialEndsAt, locale);
    // Never the default plan here: a charge we announce has to be the real one.
    const price = formatPrice(subscription.plan);

    return (
        <div>
            <div className="flex items-center gap-2">
                <SparklesIcon className="size-5 text-emerald-500" />
                <span className="font-medium">
                    {__('Free trial — ends :date', { date })}
                </span>
            </div>
            <p className="mt-2 text-sm">
                {subscription.onGracePeriod
                    ? __(
                          "You canceled your plan, so you won't be charged. Standard stays on until the trial ends.",
                      )
                    : price
                      ? __('First charge of :price on :date.', {
                            price,
                            date,
                        })
                      : __('Your first charge will be on :date.', { date })}
            </p>
        </div>
    );
}

function ManageSubscriptionButton({
    label = __('Manage Subscription'),
    variant = 'default',
    source,
}: {
    label?: string;
    variant?: 'default' | 'outline';
    /** Which of the page's ways into the portal was taken, for analytics. */
    source: 'manage' | 'payment_failed';
}) {
    return (
        <Button variant={variant} asChild>
            <a
                href={
                    source === 'payment_failed'
                        ? portal.url({
                              query: { flow: 'payment_method_update' },
                          })
                        : portal.url()
                }
                onClick={() =>
                    captureEvent('billing_portal_opened', { source })
                }
            >
                <CreditCardIcon className="size-4" />
                {label}
            </a>
        </Button>
    );
}

/**
 * The way into the in-app cancel flow. The portal button next to it stays for
 * the card and the invoices.
 */
function CancelSubscriptionButton({
    onTrial,
    currency,
    locale,
}: {
    onTrial: boolean;
    currency: string;
    locale: string;
}) {
    const [open, setOpen] = useState(false);
    // A new flow every time it opens: one that ended (an offer taken) or was
    // walked away from must not reopen on its old step.
    const [attempt, setAttempt] = useState(0);

    return (
        <>
            <Button
                variant="ghost"
                className="text-muted-foreground"
                onClick={() => {
                    captureEvent('cancel_flow_started', { on_trial: onTrial });
                    setAttempt((current) => current + 1);
                    setOpen(true);
                }}
                data-testid="cancel-subscription"
            >
                {__('Cancel subscription')}
            </Button>
            <CancelSubscriptionFlow
                key={attempt}
                open={open}
                onOpenChange={setOpen}
                onTrial={onTrial}
                currency={currency}
                locale={locale}
            />
        </>
    );
}

/**
 * For a plan the user set to cancel and that is still running (Cashier's grace
 * period): what stops and when, and the way back, one click away. Most trial
 * cancellations happen within the first hour, as a precaution, and until now
 * the only way to take one back was buried in Stripe's portal.
 */
function ScheduledCancellationSection({
    onTrial,
    endsAt,
    locale,
}: {
    onTrial: boolean;
    endsAt: string;
    locale: string;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [processing, setProcessing] = useState(false);
    const date = formatDateMedium(endsAt, locale);
    const analytics = { on_trial: onTrial };

    const keepPlan = () => {
        captureEvent('subscription_resume_clicked', analytics);
        setProcessing(true);
        router.post(
            resume.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const { flash } = page.props as unknown as SharedData;

                    captureEvent('subscription_resumed', analytics);

                    if (flash?.success) {
                        toast.success(flash.success);
                    }
                },
                onError: () =>
                    captureEvent('subscription_resume_failed', analytics),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Alert data-testid="scheduled-cancellation">
            <CalendarClockIcon className="h-4 w-4" />
            <AlertTitle>{__('Standard stops on :date', { date })}</AlertTitle>
            <AlertDescription className="flex flex-col items-start gap-3">
                <span>
                    {onTrial
                        ? __(
                              'Bank sync and AI categorization stop that day. Keep your plan and your first charge is on :date.',
                              { date },
                          )
                        : __(
                              "You won't be charged again. Bank sync and AI categorization stop that day. Keep your plan and it renews as usual.",
                          )}
                </span>
                {errors.resume && (
                    <span className="text-destructive">{errors.resume}</span>
                )}
                <Button
                    disabled={processing}
                    onClick={keepPlan}
                    data-testid="resume-subscription"
                >
                    {__('Keep my plan')}
                </Button>
            </AlertDescription>
        </Alert>
    );
}

/**
 * For a subscription whose charge failed (`past_due`, `unpaid`, `incomplete`):
 * Stripe is still chasing the invoice, so stopping it has to be one click away
 * here. Stripe's portal is offered only to pay — an `incomplete` subscription is
 * not even listed there — and never as the only way to cancel.
 *
 * A payment the bank holds for 3D Secure is not fixed by a retry nor a new
 * card, so then the alert leads with confirming it on Cashier's payment page,
 * which leaves the SPA.
 */
function UnpaidSubscriptionSection({
    confirmationUrl,
}: {
    confirmationUrl: string | null;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const cancel = () => {
        setProcessing(true);
        router.post(
            cancelUnpaid.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(__('Your subscription was canceled.')),
                onFinish: () => {
                    setProcessing(false);
                    setConfirming(false);
                },
            },
        );
    };

    return (
        <Alert
            variant="destructive"
            className="border-destructive/30 bg-destructive/10"
        >
            <TriangleAlertIcon className="h-4 w-4" />
            <AlertTitle>
                {confirmationUrl
                    ? __('Your bank needs you to confirm the payment')
                    : __('Your payment failed')}
            </AlertTitle>
            <AlertDescription className="flex flex-col items-start gap-3">
                <span>
                    {confirmationUrl
                        ? __(
                              'Your bank asked you to approve the charge for your subscription before it goes through. Confirm it to keep Standard, or cancel and we stop trying to charge you.',
                          )
                        : __(
                              'We could not charge your card for your subscription. Cancel it and we stop trying to charge you, or update your payment method to keep Standard.',
                          )}
                </span>
                {errors.subscription && (
                    <span role="alert">{errors.subscription}</span>
                )}
                {confirming && (
                    <span className="font-medium">
                        {__(
                            'Your plan ends right away and we stop charging your card.',
                        )}
                    </span>
                )}
                <div className="flex flex-wrap items-center gap-3">
                    {confirming ? (
                        <>
                            <Button
                                variant="destructive"
                                disabled={processing}
                                onClick={cancel}
                            >
                                {__('Confirm cancellation')}
                            </Button>
                            <Button
                                variant="ghost"
                                disabled={processing}
                                onClick={() => setConfirming(false)}
                            >
                                {__('Keep my plan')}
                            </Button>
                        </>
                    ) : (
                        <>
                            {confirmationUrl && (
                                <Button
                                    variant="destructive"
                                    onClick={() => leavePage(confirmationUrl)}
                                >
                                    {__('Confirm payment')}
                                </Button>
                            )}
                            <Button
                                variant={
                                    confirmationUrl ? 'ghost' : 'destructive'
                                }
                                onClick={() => setConfirming(true)}
                            >
                                {__('Cancel subscription')}
                            </Button>
                            <ManageSubscriptionButton
                                label={__('Update payment method')}
                                variant="outline"
                                source="payment_failed"
                            />
                        </>
                    )}
                </div>
            </AlertDescription>
        </Alert>
    );
}

function SubscribedSection({
    isSharedAccount,
    showsPaymentFailure,
    defaultPlan,
    subscription,
    currency,
    locale,
}: {
    isSharedAccount: boolean;
    /** The payment-failure alert above already links to the portal. */
    showsPaymentFailure: boolean;
    defaultPlan: Plan | undefined;
    subscription?: SubscriptionInfo | null;
    currency: string;
    locale: string;
}) {
    return (
        <div className="space-y-6">
            <HeadingSmall
                title={__('Your Standard plan')}
                description={__("You're enjoying all the benefits of Standard")}
            />

            {isSharedAccount && (
                <Alert>
                    <InfoIcon className="h-4 w-4" />
                    <AlertDescription>
                        {__(
                            'Billing management is not available on this shared account.',
                        )}
                    </AlertDescription>
                </Alert>
            )}

            {subscription?.onGracePeriod &&
                subscription.endsAt &&
                !isSharedAccount && (
                    <ScheduledCancellationSection
                        onTrial={subscription.onTrial}
                        endsAt={subscription.endsAt}
                        locale={locale}
                    />
                )}

            {subscription?.pause && !isSharedAccount && (
                <PausedPlanNotice
                    pause={subscription.pause}
                    currency={currency}
                    locale={locale}
                />
            )}

            <BenefitsGrid />

            <div className="rounded-lg border bg-card p-5">
                <PlanStatus
                    subscription={subscription}
                    defaultPlan={defaultPlan}
                    currency={currency}
                    locale={locale}
                />
                <p className="mt-2 text-sm text-muted-foreground">
                    {__(
                        'Manage your subscription, update payment methods, or view invoices through the Stripe billing portal.',
                    )}
                </p>
                {!isSharedAccount && !showsPaymentFailure && (
                    <div className="mt-4 flex flex-wrap items-center gap-3">
                        <ManageSubscriptionButton source="manage" />
                        {subscription?.canCancel && (
                            <CancelSubscriptionButton
                                onTrial={subscription.onTrial}
                                currency={currency}
                                locale={locale}
                            />
                        )}
                    </div>
                )}
            </div>

            {subscription?.standardUpgrade && !isSharedAccount && (
                <StandardUpgradeCard
                    upgrade={subscription.standardUpgrade}
                    onTrial={subscription.onTrial}
                    currency={currency}
                    locale={locale}
                />
            )}
        </div>
    );
}

/**
 * Inside a pause there is no plan, but nothing to sell either: the pause, the
 * way out of it, and the portal and the cancel flow as for any subscriber.
 */
function PausedSection({
    isSharedAccount,
    subscription,
    pause,
    currency,
    locale,
}: {
    isSharedAccount: boolean;
    subscription: SubscriptionInfo;
    pause: SubscriptionPause;
    currency: string;
    locale: string;
}) {
    return (
        <div className="space-y-6">
            <HeadingSmall
                title={__('Your Standard plan')}
                description={__('Your plan is paused')}
            />

            {isSharedAccount ? (
                <Alert>
                    <InfoIcon className="h-4 w-4" />
                    <AlertDescription>
                        {__(
                            'Billing management is not available on this shared account.',
                        )}
                    </AlertDescription>
                </Alert>
            ) : (
                <>
                    <PausedPlanNotice
                        pause={pause}
                        currency={currency}
                        locale={locale}
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <ManageSubscriptionButton
                            source="manage"
                            variant="outline"
                        />
                        {subscription.canCancel && (
                            <CancelSubscriptionButton
                                onTrial={subscription.onTrial}
                                currency={currency}
                                locale={locale}
                            />
                        )}
                    </div>
                </>
            )}
        </div>
    );
}

function AiConsentSection({
    initialConsent,
    hasProPlan,
    onUpgradeNeeded,
}: {
    initialConsent: boolean;
    hasProPlan: boolean;
    onUpgradeNeeded: () => void;
}) {
    const [consented, setConsented] = useState(initialConsent);
    const [saving, setSaving] = useState(false);

    const handleToggle = async (checked: boolean) => {
        // Free users can't enable AI directly — it needs a paid plan. Prompt
        // them to subscribe instead of recording consent (which would silently
        // lock them behind the paywall on the next navigation).
        if (checked && !hasProPlan) {
            onUpgradeNeeded();
            return;
        }

        setSaving(true);
        try {
            if (checked) {
                await axios.post(storeConsent.url());
            } else {
                await axios.delete(revokeConsent.url());
            }
            setConsented(checked);
            toast.success(
                checked
                    ? __('AI categorization enabled')
                    : __('AI categorization disabled'),
            );
        } catch {
            toast.error(__('Something went wrong.'));
        } finally {
            setSaving(false);
        }
    };

    const steps = [
        {
            title: __('Categorize new transactions'),
            description: __(
                "We suggest a category for new transactions that don't match any of your automation rules.",
            ),
        },
        {
            title: __('Learn your automation rules'),
            description: __(
                'We suggest and create automation rules for recurring transactions based on the corrections you make, so similar ones are categorized for you next time.',
            ),
        },
    ];

    return (
        <div className="space-y-6">
            <header>
                <div className="mb-0.5 flex items-center gap-2">
                    <h3 className="text-base font-medium">
                        {__('AI Categorization')}
                    </h3>
                    <ProBadge />
                </div>
                <p className="text-sm text-muted-foreground">
                    {__(
                        'Let AI suggest categories for your transactions automatically.',
                    )}
                </p>
            </header>

            <div className="space-y-5 rounded-lg border bg-card p-5">
                <p className="text-sm font-medium">
                    {__('What happens when you turn this on')}
                </p>

                <ol className="space-y-4">
                    {steps.map((step, index) => (
                        <li key={step.title} className="flex items-start gap-3">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-xs font-semibold text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400">
                                {index + 1}
                            </span>
                            <div>
                                <p className="text-sm font-medium">
                                    {step.title}
                                </p>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {step.description}
                                </p>
                            </div>
                        </li>
                    ))}
                </ol>

                <div className="flex items-start gap-3 rounded-md bg-muted/50 p-3">
                    <ShieldCheckIcon className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    <p className="text-sm text-muted-foreground">
                        {__(
                            "To do this, we send each transaction's description, amount, and the merchant or sender name to our AI provider, along with the names of your categories so it can pick one. We never send your full financial picture, and your data is never used to train AI models.",
                        )}
                    </p>
                </div>
            </div>

            <div className="rounded-lg border bg-card p-5">
                <label className="flex items-start gap-3">
                    <Checkbox
                        checked={consented}
                        disabled={saving}
                        onCheckedChange={(checked) =>
                            handleToggle(checked === true)
                        }
                        className="mt-0.5"
                    />
                    <div>
                        <span className="font-medium">
                            {__('Allow AI categorization')}
                        </span>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {__('You can turn this off at any time.')}
                        </p>
                    </div>
                </label>
            </div>
        </div>
    );
}

export default function Billing() {
    const {
        auth,
        pricing,
        locale,
        hasAiConsent,
        subscription,
        hasUnpaidSubscription,
        paymentConfirmationUrl,
    } = usePage<
        SharedData & {
            hasAiConsent: boolean;
            hasUnpaidSubscription: boolean;
            paymentConfirmationUrl: string | null;
            subscription?: SubscriptionInfo | null;
        }
    >().props;
    const isSharedAccount = auth?.isSharedAccount ?? false;
    const hasProPlan = auth?.hasProPlan ?? false;
    const defaultPlan = pricing.plans[pricing.defaultPlan];
    const [showAiUpgrade, setShowAiUpgrade] = useState(false);
    const showsPaymentFailure = hasUnpaidSubscription && !isSharedAccount;
    // Inside a pause the plan is off, yet there is nothing to sell.
    const pausedSubscription =
        subscription?.pause?.active === true
            ? { ...subscription, pause: subscription.pause }
            : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Manage Plan')} />

            <SettingsLayout>
                <div className="space-y-8">
                    {/* Hidden on a shared account: the consent routes are
                        blocked, so the toggle could only fail. */}
                    {!isSharedAccount && (
                        <AiConsentSection
                            initialConsent={hasAiConsent}
                            hasProPlan={hasProPlan}
                            onUpgradeNeeded={() => setShowAiUpgrade(true)}
                        />
                    )}

                    {showsPaymentFailure && (
                        <UnpaidSubscriptionSection
                            confirmationUrl={paymentConfirmationUrl}
                        />
                    )}

                    {pausedSubscription ? (
                        <PausedSection
                            isSharedAccount={isSharedAccount}
                            subscription={pausedSubscription}
                            pause={pausedSubscription.pause}
                            currency={pricing.currency}
                            locale={locale}
                        />
                    ) : hasProPlan ? (
                        <SubscribedSection
                            isSharedAccount={isSharedAccount}
                            showsPaymentFailure={showsPaymentFailure}
                            defaultPlan={defaultPlan}
                            subscription={subscription}
                            currency={pricing.currency}
                            locale={locale}
                        />
                    ) : showsPaymentFailure ? null : (
                        <UpgradeSection
                            defaultPlan={pricing.defaultPlan}
                            currency={pricing.currency}
                            locale={locale}
                        />
                    )}

                    {!hasProPlan && (
                        <UpgradeDialog
                            open={showAiUpgrade}
                            onOpenChange={setShowAiUpgrade}
                            source="ai_categorization"
                        />
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
