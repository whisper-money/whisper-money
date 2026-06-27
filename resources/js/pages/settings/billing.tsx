import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import {
    destroy as revokeConsent,
    store as storeConsent,
} from '@/routes/ai/consent';
import { billing } from '@/routes/settings';
import { portal, refund as refundRoute } from '@/routes/settings/billing';
import { checkout } from '@/routes/subscribe';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    CheckIcon,
    CreditCardIcon,
    InfinityIcon,
    InfoIcon,
    LandmarkIcon,
    ShieldCheckIcon,
    SparklesIcon,
    ZapIcon,
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
            'Your data is never shared with third parties. You are always the owner.',
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

function PlanCard({
    plan,
    isSelected,
    onSelect,
    currency,
    locale,
}: {
    planKey: string;
    plan: Plan;
    isSelected: boolean;
    onSelect: () => void;
    currency: string;
    locale: string;
}) {
    const savingsPercent =
        plan.original_price && plan.billing_period === 'year'
            ? Math.round(
                  ((plan.original_price - plan.price) / plan.original_price) *
                      100,
              )
            : null;
    const monthlyEquivalent =
        plan.billing_period === 'year' ? plan.price / 12 : plan.price;

    return (
        <button
            type="button"
            onClick={onSelect}
            className={cn(
                'flex flex-1 flex-col rounded-lg border p-3 text-left transition-all',
                isSelected
                    ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500 dark:bg-emerald-950/30'
                    : 'border-border bg-card hover:border-muted-foreground/50',
            )}
        >
            <div className="flex items-center gap-2">
                <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {plan.billing_period === 'year'
                        ? __('Annual')
                        : __('Monthly')}
                </span>
                {savingsPercent && savingsPercent > 0 && (
                    <span className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        {__('Saving')} {savingsPercent}%
                    </span>
                )}
            </div>
            <div className="mt-1 flex items-baseline gap-1">
                <span className="text-xl font-bold">
                    {formatCurrency(monthlyEquivalent * 100, currency, locale)}
                </span>
                <span className="text-sm text-muted-foreground">
                    {__('/month')}
                </span>
            </div>
            {plan.billing_period === 'year' && (
                <span className="mt-2 text-xs text-muted-foreground">
                    {__('Billed annually at')}{' '}
                    {formatCurrency(plan.price * 100, currency, locale)}
                </span>
            )}
        </button>
    );
}

function UpgradeSection({
    planEntries,
    defaultPlan,
    currency,
    locale,
}: {
    planEntries: [string, Plan][];
    defaultPlan: string;
    currency: string;
    locale: string;
}) {
    const [selectedPlan, setSelectedPlan] = useState(defaultPlan);
    const selectedPlanData = planEntries.find(
        ([key]) => key === selectedPlan,
    )?.[1];

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
                <p className="mb-4 text-sm font-medium">
                    {__('Choose your billing cycle')}
                </p>

                <div className="flex gap-3">
                    {planEntries.map(([key, plan]) => (
                        <PlanCard
                            key={key}
                            planKey={key}
                            plan={plan}
                            isSelected={key === selectedPlan}
                            onSelect={() => setSelectedPlan(key)}
                            currency={currency}
                            locale={locale}
                        />
                    ))}
                </div>

                {selectedPlanData && selectedPlanData.features.length > 0 && (
                    <ul className="mt-4 grid grid-cols-2 gap-x-4 gap-y-1.5">
                        {selectedPlanData.features
                            .slice(0, 4)
                            .map((feature) => (
                                <li
                                    key={feature}
                                    className="flex items-center gap-1.5"
                                >
                                    <CheckIcon className="size-3.5 shrink-0 text-emerald-500" />
                                    <span className="text-xs text-muted-foreground">
                                        {__(feature)}
                                    </span>
                                </li>
                            ))}
                    </ul>
                )}

                <a
                    href={checkout.url({
                        query: { plan: selectedPlan },
                    })}
                    className="mt-4 block"
                >
                    <Button className="w-full bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-700">
                        <ZapIcon className="size-4" />
                        {__('Upgrade to Standard Plan')}
                    </Button>
                </a>
            </div>
        </div>
    );
}

interface RefundInfo {
    canSelfRefund: boolean;
    deadline: string | null;
}

function RefundCard({
    deadline,
    locale,
}: {
    deadline: string | null;
    locale: string;
}) {
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);

    const deadlineLabel = deadline
        ? new Date(deadline).toLocaleDateString(locale, {
              day: 'numeric',
              month: 'long',
          })
        : null;

    const submit = () => {
        setProcessing(true);
        router.post(
            refundRoute.url(),
            {},
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <div className="rounded-lg border border-amber-200 bg-amber-50 p-5 dark:border-amber-900 dark:bg-amber-950/30">
            <h4 className="font-medium text-amber-900 dark:text-amber-200">
                {__('Money-back guarantee')}
            </h4>
            <p className="mt-1 text-sm text-amber-800/80 dark:text-amber-300/80">
                {deadlineLabel
                    ? __(
                          'Changed your mind? Request a full refund until :date. This cancels your subscription and disconnects your bank accounts — the data you already imported is kept.',
                          { date: deadlineLabel },
                      )
                    : __(
                          'Changed your mind? Request a full refund. This cancels your subscription and disconnects your bank accounts — the data you already imported is kept.',
                      )}
            </p>

            {confirming ? (
                <div className="mt-4 flex flex-wrap gap-2">
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={submit}
                    >
                        {__('Confirm refund')}
                    </Button>
                    <Button
                        variant="ghost"
                        disabled={processing}
                        onClick={() => setConfirming(false)}
                    >
                        {__('Keep my plan')}
                    </Button>
                </div>
            ) : (
                <Button
                    variant="outline"
                    className="mt-4"
                    onClick={() => setConfirming(true)}
                >
                    {__('Request a refund')}
                </Button>
            )}
        </div>
    );
}

function SubscribedSection({
    isDemoAccount,
    defaultPlan,
    currency,
    locale,
    refund,
}: {
    isDemoAccount: boolean;
    defaultPlan: Plan | undefined;
    currency: string;
    locale: string;
    refund?: RefundInfo;
}) {
    return (
        <div className="space-y-6">
            <HeadingSmall
                title={__('Your Pro Plan')}
                description={__(
                    "You're enjoying all the benefits of Whisper Money Pro",
                )}
            />

            {isDemoAccount && (
                <Alert>
                    <InfoIcon className="h-4 w-4" />
                    <AlertDescription>
                        {__(
                            'Billing management is not available on the demo account.',
                        )}
                    </AlertDescription>
                </Alert>
            )}

            <BenefitsGrid />

            <div className="rounded-lg border bg-card p-5">
                <div className="flex items-center gap-2">
                    <CheckIcon className="size-5 text-emerald-500" />
                    <span className="font-medium">{__('Pro Plan Active')}</span>
                    {defaultPlan && (
                        <span className="text-muted-foreground">
                            {`\u2014 ${formatCurrency(defaultPlan.price * 100, currency, locale)}/${defaultPlan.billing_period}`}
                        </span>
                    )}
                </div>
                <p className="mt-2 text-sm text-muted-foreground">
                    {__(
                        'Manage your subscription, update payment methods, or view invoices through the Stripe billing portal.',
                    )}
                </p>
                {!isDemoAccount && (
                    <a href={portal.url()}>
                        <Button className="mt-4">
                            <CreditCardIcon className="size-4" />
                            {__('Manage Subscription')}
                        </Button>
                    </a>
                )}
            </div>

            {refund?.canSelfRefund && (
                <RefundCard deadline={refund.deadline} locale={locale} />
            )}
        </div>
    );
}

function AiConsentSection({
    initialConsent,
    hasProPlan,
}: {
    initialConsent: boolean;
    hasProPlan: boolean;
}) {
    const [consented, setConsented] = useState(initialConsent);
    const [saving, setSaving] = useState(false);

    const handleToggle = async (checked: boolean) => {
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

    return (
        <div className="space-y-6">
            <header>
                <div className="mb-0.5 flex items-center gap-2">
                    <h3 className="text-base font-medium">
                        {__('AI Categorization')}
                    </h3>
                    <Badge
                        variant="secondary"
                        className="bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400"
                    >
                        PRO
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    {__(
                        'Let AI suggest categories for your transactions automatically.',
                    )}
                </p>
            </header>

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
                            {__(
                                'With your permission, we send merchant names from your transactions to our AI provider so it can suggest categories. You can revoke this at any time.',
                            )}
                        </p>
                    </div>
                </label>

                {!hasProPlan && (
                    <p className="mt-3 border-t pt-3 text-sm text-amber-600 dark:text-amber-400">
                        {__(
                            'You can give consent now, but AI categorization only runs while you have a paid plan.',
                        )}
                    </p>
                )}
            </div>
        </div>
    );
}

export default function Billing() {
    const { auth, pricing, locale, features, hasAiConsent, refund } = usePage<
        SharedData & { hasAiConsent: boolean; refund?: RefundInfo }
    >().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;
    const hasProPlan = auth?.hasProPlan ?? false;
    const planEntries = Object.entries(pricing.plans);
    const defaultPlan = pricing.plans[pricing.defaultPlan];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Manage Plan')} />

            <SettingsLayout>
                <div className="space-y-8">
                    {hasProPlan ? (
                        <SubscribedSection
                            isDemoAccount={isDemoAccount}
                            defaultPlan={defaultPlan}
                            currency={pricing.currency}
                            locale={locale}
                            refund={refund}
                        />
                    ) : (
                        <UpgradeSection
                            planEntries={planEntries}
                            defaultPlan={pricing.defaultPlan}
                            currency={pricing.currency}
                            locale={locale}
                        />
                    )}

                    {features.aiConsentSettings && (
                        <AiConsentSection
                            initialConsent={hasAiConsent}
                            hasProPlan={hasProPlan}
                        />
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
