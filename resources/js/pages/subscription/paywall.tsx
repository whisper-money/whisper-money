import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useCountUp } from '@/hooks/use-count-up';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as connectionsIndex } from '@/routes/settings/connections';
import { checkout } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CheckIcon,
    FolderIcon,
    LandmarkIcon,
    LockIcon,
    PiggyBankIcon,
    ReceiptIcon,
    TrendingUpIcon,
    UsersIcon,
    WalletIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';

interface PaywallStats {
    accountsCount: number;
    transactionsCount: number;
    categoriesCount: number;
    automationRulesCount: number;
    balancesByCurrency: Record<string, number>;
}

interface ExperimentOffer {
    variant: string;
    payNow: boolean;
    refundWindowDays: number;
    trialDays: Record<string, number>;
}

interface PaywallPageProps extends SharedData {
    stats: PaywallStats;
    canUseFreePlan: boolean;
    canManageConnectionsForFreePlan: boolean;
    offer: ExperimentOffer;
}

function TrialTerms({
    offer,
    planKey,
    amount,
}: {
    offer: ExperimentOffer;
    planKey: string;
    amount: string;
}) {
    if (offer.payNow) {
        return (
            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-center text-sm dark:border-emerald-900 dark:bg-emerald-950/40">
                <p className="font-medium text-emerald-900 dark:text-emerald-200">
                    {__('Try it for :days days', {
                        days: offer.refundWindowDays,
                    })}
                </p>
                <p className="mt-1 text-emerald-800/80 dark:text-emerald-300/80">
                    {__(
                        "Don't like it? Get a full refund with one tap from Settings — no questions asked.",
                    )}
                </p>
                <p className="mt-1 text-xs text-emerald-700/70 dark:text-emerald-300/60">
                    {__(
                        'You pay :amount today, refunded in full if you cancel within :days days.',
                        { amount, days: offer.refundWindowDays },
                    )}
                </p>
            </div>
        );
    }

    const days = offer.trialDays[planKey] ?? 0;

    if (days <= 0) {
        return null;
    }

    return (
        <div className="rounded-lg border bg-muted/30 p-3 text-center text-sm">
            <p className="font-medium">
                {__(':days-day free trial', { days })}
            </p>
            <p className="mt-1 text-muted-foreground">
                {__(
                    "You won't be charged until your :days-day trial ends. Cancel anytime before then.",
                    { days },
                )}
            </p>
        </div>
    );
}

function getEquivalentBillingLabel(
    billingPeriod: string | null,
    t: typeof __,
): string {
    if (!billingPeriod) {
        return t('one-time');
    }

    return t('/month');
}

const socialProofs = [
    {
        icon: TrendingUpIcon,
        highlightKey: '15% more savings',
        textKey: 'after 3 months with Whisper Money',
    },
    {
        icon: PiggyBankIcon,
        highlightKey: '23% better',
        textKey: 'spending awareness reported',
    },
    {
        icon: LockIcon,
        highlightKey: '100% private',
        textKey: '- we never sell your data',
    },
    {
        icon: UsersIcon,
        highlightKey: '1,200+ users',
        textKey: 'taking control of their finances',
    },
];

function SocialProofSlider() {
    const [currentIndex, setCurrentIndex] = useState(0);

    useEffect(() => {
        const interval = setInterval(() => {
            setCurrentIndex((prev) => (prev + 1) % socialProofs.length);
        }, 4000);
        return () => clearInterval(interval);
    }, []);

    const currentProof = socialProofs[currentIndex];
    const Icon = currentProof.icon;

    return (
        <div className="flex flex-col items-center gap-4">
            <div
                key={`icon-${currentIndex}`}
                className="flex h-16 w-16 animate-in items-center justify-center rounded-full bg-emerald-100 duration-500 zoom-in-95 fade-in dark:bg-emerald-900/30"
            >
                <Icon className="h-8 w-8 text-emerald-600 dark:text-emerald-400" />
            </div>

            <div className="relative w-full overflow-hidden text-center">
                <p
                    key={currentIndex}
                    className="animate-in text-lg text-balance duration-500 fade-in slide-in-from-right-4"
                >
                    <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                        {__(currentProof.highlightKey)}
                    </span>{' '}
                    <span className="text-muted-foreground">
                        {__(currentProof.textKey)}
                    </span>
                </p>
            </div>

            <div className="flex gap-1.5">
                {socialProofs.map((_, index) => (
                    <button
                        key={index}
                        onClick={() => setCurrentIndex(index)}
                        className={cn(
                            'h-1.5 rounded-full transition-all',
                            index === currentIndex
                                ? 'w-4 bg-emerald-500'
                                : 'w-1.5 bg-muted-foreground/30 hover:bg-muted-foreground/50',
                        )}
                        aria-label={`Go to slide ${index + 1}`}
                    />
                ))}
            </div>
        </div>
    );
}

function StatItem({
    icon: Icon,
    value,
    label,
    delay = 0,
}: {
    icon: React.ElementType;
    value: number;
    label: string;
    delay?: number;
}) {
    const animatedValue = useCountUp(value, { delay });

    return (
        <div className="flex flex-1 flex-col items-center gap-0.5">
            <Icon className="mb-1.5 h-4 w-4 text-emerald-500" />
            <span className="text-xl font-bold">{animatedValue}</span>
            <span className="text-xs text-muted-foreground">{label}</span>
        </div>
    );
}

function BalanceDisplay({
    balancesByCurrency,
}: {
    balancesByCurrency: Record<string, number>;
}) {
    const locale = useLocale();
    const entries = Object.entries(balancesByCurrency);

    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-1 flex-col items-center gap-0.5">
            <WalletIcon className="mb-1.5 h-4 w-4 text-emerald-500" />
            <div className="flex flex-col items-center">
                {entries.map(([currency, amount]) => (
                    <span key={currency} className="text-xl font-bold">
                        {formatCurrency(
                            Math.abs(amount),
                            currency,
                            locale,
                            0,
                            0,
                        )}
                    </span>
                ))}
            </div>
            <span className="text-xs text-muted-foreground">
                {__('Balance')}
            </span>
        </div>
    );
}

function FinancialSnapshot({ stats }: { stats: PaywallStats }) {
    const hasData =
        stats.accountsCount > 0 ||
        stats.transactionsCount > 0 ||
        stats.categoriesCount > 0;

    if (!hasData) {
        return null;
    }

    return (
        <Card className="animate-in duration-500 [animation-delay:200ms] fade-in">
            <CardContent className="flex flex-row gap-6">
                {stats.accountsCount > 0 && (
                    <StatItem
                        icon={PiggyBankIcon}
                        value={stats.accountsCount}
                        label={__('Accounts')}
                        delay={100}
                    />
                )}
                {stats.transactionsCount > 0 && (
                    <StatItem
                        icon={ReceiptIcon}
                        value={stats.transactionsCount}
                        label={__('Transactions')}
                        delay={200}
                    />
                )}
                {stats.categoriesCount > 0 && (
                    <StatItem
                        icon={FolderIcon}
                        value={stats.categoriesCount}
                        label={__('Categories')}
                        delay={300}
                    />
                )}
                {Object.keys(stats.balancesByCurrency).length > 0 && (
                    <BalanceDisplay
                        balancesByCurrency={stats.balancesByCurrency}
                    />
                )}
            </CardContent>
        </Card>
    );
}

function FeaturesSection({ features }: { features: string[] }) {
    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center gap-3 rounded-lg border border-emerald-100/50 bg-emerald-50/25 px-4 py-3 dark:border-emerald-800/50 dark:bg-emerald-950/20">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/50">
                    <LandmarkIcon className="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div className="flex-1">
                    <p className="text-sm font-semibold">
                        {__('Connected banks')}
                    </p>
                    <p className="text-xs text-balance text-muted-foreground">
                        {__(
                            'Sync transactions and balances automatically. Forget about manually importing CSVs from your bank.',
                        )}
                    </p>
                </div>
                <CheckIcon className="h-4 w-4 shrink-0 text-emerald-500" />
            </div>

            {features.length > 0 && (
                <ul className="grid grid-cols-2 gap-x-4 gap-y-1.5 px-1">
                    {features.map((feature) => (
                        <li key={feature} className="flex items-center gap-1.5">
                            <CheckIcon className="size-3.5 shrink-0 text-emerald-500" />
                            <span className="text-xs text-muted-foreground">
                                {__(feature)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function CompactPlanCard({
    plan,
    isSelected,
    onSelect,
    currency,
}: {
    plan: Plan;
    isSelected: boolean;
    onSelect: () => void;
    currency: string;
}) {
    const locale = useLocale();
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
                    {getEquivalentBillingLabel(plan.billing_period, __)}
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

function PricingSection({
    planEntries,
    defaultPlan,
    currency,
    canUseFreePlan,
    canManageConnectionsForFreePlan,
    offer,
}: {
    planEntries: [string, Plan][];
    defaultPlan: string;
    currency: string;
    canUseFreePlan: boolean;
    canManageConnectionsForFreePlan: boolean;
    offer: ExperimentOffer;
}) {
    const [selectedPlan, setSelectedPlan] = useState(defaultPlan);
    const [freeButtonVisible, setFreeButtonVisible] = useState(false);
    const locale = useLocale();

    const selectedPlanData = planEntries.find(
        ([key]) => key === selectedPlan,
    )?.[1];

    const selectedAmount = selectedPlanData
        ? formatCurrency(selectedPlanData.price * 100, currency, locale)
        : '';

    useEffect(() => {
        if (!canUseFreePlan) {
            return;
        }
        const timer = setTimeout(() => setFreeButtonVisible(true), 5000);
        return () => clearTimeout(timer);
    }, [canUseFreePlan]);

    return (
        <div className="flex flex-col gap-4">
            {selectedPlanData && (
                <FeaturesSection
                    features={selectedPlanData.features.filter(
                        (feature) => feature !== 'Connect bank accounts',
                    )}
                />
            )}

            <div className="flex gap-3">
                {planEntries.map(([key, plan]) => (
                    <CompactPlanCard
                        key={key}
                        plan={plan}
                        isSelected={key === selectedPlan}
                        onSelect={() => setSelectedPlan(key)}
                        currency={currency}
                    />
                ))}
            </div>

            <TrialTerms
                offer={offer}
                planKey={selectedPlan}
                amount={selectedAmount}
            />

            <a href={checkout.url({ query: { plan: selectedPlan } })}>
                <Button
                    className="w-full bg-emerald-600 py-6 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-700"
                    size="lg"
                >
                    {__('Start My Financial Journey')}
                </Button>
            </a>

            {canManageConnectionsForFreePlan && (
                <div className="rounded-lg border bg-muted/30 p-3 text-center">
                    <p className="mb-3 text-sm text-muted-foreground">
                        {__(
                            'Want to continue for free? Disconnect all bank connections in Settings.',
                        )}
                    </p>
                    <Button
                        variant="outline"
                        className="w-full"
                        onClick={() => router.visit(connectionsIndex().url)}
                    >
                        {__('Go to Settings')}
                    </Button>
                </div>
            )}

            {canUseFreePlan && (
                <div
                    className={cn(
                        'transition-opacity duration-1000',
                        freeButtonVisible
                            ? 'opacity-100'
                            : 'pointer-events-none opacity-0',
                    )}
                >
                    <Button
                        variant="ghost"
                        className="w-full"
                        onClick={() => router.visit(dashboard().url)}
                    >
                        {__('Continue for free')}
                    </Button>
                </div>
            )}
        </div>
    );
}

export default function Paywall() {
    const {
        pricing,
        stats,
        canUseFreePlan,
        canManageConnectionsForFreePlan,
        offer,
    } = usePage<PaywallPageProps>().props;
    const planEntries = Object.entries(pricing.plans);

    if (planEntries.length === 0) {
        return null;
    }

    return (
        <>
            <Head title={__('Start Your Financial Journey')} />

            <div className="flex min-h-screen flex-col items-center justify-center bg-background px-4 py-8">
                <div className="flex w-full max-w-md flex-col gap-6">
                    <SocialProofSlider />

                    <FinancialSnapshot stats={stats} />

                    <PricingSection
                        planEntries={planEntries}
                        defaultPlan={pricing.defaultPlan}
                        currency={pricing.currency}
                        canUseFreePlan={canUseFreePlan}
                        canManageConnectionsForFreePlan={
                            canManageConnectionsForFreePlan
                        }
                        offer={offer}
                    />
                </div>
            </div>
        </>
    );
}
