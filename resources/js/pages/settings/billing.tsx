import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { cn } from '@/lib/utils';
import { billing } from '@/routes/settings';
import { portal } from '@/routes/settings/billing';
import { checkout } from '@/routes/subscribe';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
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
}: {
    planKey: string;
    plan: Plan;
    isSelected: boolean;
    onSelect: () => void;
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
                    ${monthlyEquivalent.toFixed(0)}
                </span>
                <span className="text-sm text-muted-foreground">
                    {__('/month')}
                </span>
            </div>
            {plan.billing_period === 'year' && (
                <span className="mt-2 text-xs text-muted-foreground">
                    {__('Billed annually at')} ${plan.price}
                </span>
            )}
        </button>
    );
}

function UpgradeSection({
    planEntries,
    defaultPlan,
}: {
    planEntries: [string, Plan][];
    defaultPlan: string;
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

function SubscribedSection({
    isDemoAccount,
    defaultPlan,
    currency,
    locale,
}: {
    isDemoAccount: boolean;
    defaultPlan: Plan | undefined;
    currency: string;
    locale: string;
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
        </div>
    );
}

export default function Billing() {
    const { auth, pricing, locale } = usePage<SharedData>().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;
    const hasProPlan = auth?.hasProPlan ?? false;
    const planEntries = Object.entries(pricing.plans);
    const defaultPlan = pricing.plans[pricing.defaultPlan];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Manage Plan')} />

            <SettingsLayout>
                {hasProPlan ? (
                    <SubscribedSection
                        isDemoAccount={isDemoAccount}
                        defaultPlan={defaultPlan}
                        currency={pricing.currency}
                        locale={locale}
                    />
                ) : (
                    <UpgradeSection
                        planEntries={planEntries}
                        defaultPlan={pricing.defaultPlan}
                    />
                )}
            </SettingsLayout>
        </AppLayout>
    );
}
