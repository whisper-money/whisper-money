import HeadingSmall from '@/components/heading-small';
import { ProBadge } from '@/components/pro-badge';
import { PlanPicker, planTerms } from '@/components/subscription/plan-picker';
import { UpgradeDialog } from '@/components/subscription/upgrade-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    destroy as revokeConsent,
    store as storeConsent,
} from '@/routes/ai/consent';
import { billing } from '@/routes/settings';
import { portal } from '@/routes/settings/billing';
import { checkout } from '@/routes/subscribe';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    CheckIcon,
    CreditCardIcon,
    InfinityIcon,
    InfoIcon,
    LandmarkIcon,
    ShieldCheckIcon,
    SparklesIcon,
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

function UpgradeSection({
    plans,
    defaultPlan,
    currency,
    locale,
}: {
    plans: Record<string, Plan>;
    defaultPlan: string;
    currency: string;
    locale: string;
}) {
    const [selectedPlan, setSelectedPlan] = useState(defaultPlan);
    const terms = planTerms(plans[selectedPlan], currency, locale);

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
                    plans={plans}
                    currency={currency}
                    selectedPlan={selectedPlan}
                    onSelect={setSelectedPlan}
                    size="compact"
                />

                {terms && <p className="mt-4 text-sm font-medium">{terms}</p>}

                <Button asChild className="mt-4 w-full">
                    <a href={checkout.url({ query: { plan: selectedPlan } })}>
                        {__('Start a plan')}
                    </a>
                </Button>
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
    const { auth, pricing, locale, hasAiConsent } = usePage<
        SharedData & { hasAiConsent: boolean }
    >().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;
    const hasProPlan = auth?.hasProPlan ?? false;
    const defaultPlan = pricing.plans[pricing.defaultPlan];
    const [showAiUpgrade, setShowAiUpgrade] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Manage Plan')} />

            <SettingsLayout>
                <div className="space-y-8">
                    <AiConsentSection
                        initialConsent={hasAiConsent}
                        hasProPlan={hasProPlan}
                        onUpgradeNeeded={() => setShowAiUpgrade(true)}
                    />

                    {hasProPlan ? (
                        <SubscribedSection
                            isDemoAccount={isDemoAccount}
                            defaultPlan={defaultPlan}
                            currency={pricing.currency}
                            locale={locale}
                        />
                    ) : (
                        <UpgradeSection
                            plans={pricing.plans}
                            defaultPlan={pricing.defaultPlan}
                            currency={pricing.currency}
                            locale={locale}
                        />
                    )}

                    {!hasProPlan && (
                        <UpgradeDialog
                            open={showAiUpgrade}
                            onOpenChange={setShowAiUpgrade}
                            title={__('AI categorization is a paid feature')}
                            description={__(
                                'Subscribe to a plan to let AI categorize your transactions automatically. You can enable AI right after subscribing.',
                            )}
                            source="ai_categorization"
                        />
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
