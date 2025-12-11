import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCountUp } from '@/hooks/use-count-up';
import { cn } from '@/lib/utils';
import { checkout } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { Head, usePage } from '@inertiajs/react';
import {
    CheckIcon,
    FolderIcon,
    PiggyBankIcon,
    ReceiptIcon,
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

interface PaywallPageProps extends SharedData {
    stats: PaywallStats;
}

function formatCurrency(amount: number, currencyCode: string): string {
    const absAmount = Math.abs(amount) / 100;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(absAmount);
}

function getEquivalentBillingLabel(billingPeriod: string | null): string {
    if (!billingPeriod) {
        return 'one-time';
    }

    if (billingPeriod === 'year') {
        return '/month'
    }

    return `/${billingPeriod}`;
}

const socialProofs = [
    {
        highlight: '15% more savings',
        text: 'after 3 months with Whisper Money',
    },
    {
        highlight: '23% better',
        text: 'spending awareness reported',
    },
    {
        highlight: '100% private',
        text: '- we never sell your data',
    },
    {
        highlight: '1,200+ users',
        text: 'taking control of their finances',
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

    return (
        <div className="flex flex-col items-center gap-3">
            <div className="relative h-12 w-full overflow-hidden text-center">
                <p
                    key={currentIndex}
                    className="animate-in text-lg duration-500 fade-in slide-in-from-right-4"
                >
                    <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                        {currentProof.highlight}
                    </span>{' '}
                    <span className="text-muted-foreground">
                        {currentProof.text}
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
        <div className="flex flex-col items-center gap-0.5">
            <Icon className="h-4 w-4 text-emerald-500" />
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
    const entries = Object.entries(balancesByCurrency);

    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col items-center gap-0.5">
            <WalletIcon className="h-4 w-4 text-emerald-500" />
            <div className="flex flex-col items-center">
                {entries.map(([currency, amount]) => (
                    <span key={currency} className="text-xl font-bold">
                        {formatCurrency(amount, currency)}
                    </span>
                ))}
            </div>
            <span className="text-xs text-muted-foreground">Balance</span>
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

    const statCount = [
        stats.accountsCount > 0,
        stats.transactionsCount > 0,
        stats.categoriesCount > 0,
        Object.keys(stats.balancesByCurrency).length > 0,
    ].filter(Boolean).length;

    return (
        <Card className="animate-in duration-500 [animation-delay:200ms] fade-in">
            <CardContent className="flex flex-col gap-6">
                <div
                    className={cn(
                        'grid gap-4',
                        statCount <= 2 && 'grid-cols-2',
                        statCount === 3 && 'grid-cols-3',
                        statCount >= 4 && 'grid-cols-4',
                    )}
                >
                    {stats.accountsCount > 0 && (
                        <StatItem
                            icon={PiggyBankIcon}
                            value={stats.accountsCount}
                            label="Accounts"
                            delay={100}
                        />
                    )}
                    {stats.transactionsCount > 0 && (
                        <StatItem
                            icon={ReceiptIcon}
                            value={stats.transactionsCount}
                            label="Transactions"
                            delay={200}
                        />
                    )}
                    {stats.categoriesCount > 0 && (
                        <StatItem
                            icon={FolderIcon}
                            value={stats.categoriesCount}
                            label="Categories"
                            delay={300}
                        />
                    )}
                    {Object.keys(stats.balancesByCurrency).length > 0 && (
                        <BalanceDisplay
                            balancesByCurrency={stats.balancesByCurrency}
                        />
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function CompactPlanCard({
    plan,
    isSelected,
    onSelect,
}: {
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
            onClick={onSelect}
            className={cn(
                'flex flex-1 flex-col rounded-lg border p-3 text-left transition-all',
                isSelected
                    ? 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500 dark:bg-emerald-950/30'
                    : 'border-border bg-card hover:border-muted-foreground/50',
            )}
        >
            <div className='flex items-center gap-2'>
                <span className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {plan.billing_period === 'year' ? 'Annual' : 'Monthly'}
                </span>
                {savingsPercent && savingsPercent > 0 && (
                    <span className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        Save {savingsPercent}%
                    </span>
                )}
            </div>
            <div className="mt-1 flex items-baseline gap-1">
                <span className="text-xl font-bold">${monthlyEquivalent}</span>
                <span className="text-sm text-muted-foreground">
                    {getEquivalentBillingLabel(plan.billing_period)}
                </span>
            </div>
            {plan.billing_period === 'year' && (
                <span className="mt-2 text-xs text-muted-foreground">Billed annually at ${plan.price}</span>
            )}
        </button>
    );
}

function PricingSection({
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
        <div className="flex flex-col gap-4">
            <div className="flex gap-3">
                {planEntries.map(([key, plan]) => (
                    <CompactPlanCard
                        key={key}
                        plan={plan}
                        isSelected={key === selectedPlan}
                        onSelect={() => setSelectedPlan(key)}
                    />
                ))}
            </div>

            <a href={checkout.url({ query: { plan: selectedPlan } })}>
                <Button
                    className="w-full bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-700"
                    size="lg"
                >
                    Start My Financial Journey
                </Button>
            </a>

            {selectedPlanData && (
                <ul className="grid grid-cols-2 gap-x-4 gap-y-1">
                    {selectedPlanData.features.slice(0, 4).map((feature) => (
                        <li key={feature} className="flex items-center gap-1.5">
                            <CheckIcon className="size-3 shrink-0 text-emerald-500" />
                            <span className="text-xs text-muted-foreground">
                                {feature}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function PromoSection() {
    return (
        <p className="flex items-center justify-center gap-2 text-center text-xs text-muted-foreground">
            <span>Your data is ready</span>
            <span>•</span>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <a
                            href="https://discord.gg/9UQWZECDDv"
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-[#5865F2] underline-offset-2 hover:underline"
                        >
                            Discord for $8 off
                        </a>
                    </TooltipTrigger>
                    <TooltipContent>
                        You'll receive an exclusive promo code via DM!
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </p>
    );
}

export default function Paywall() {
    const { pricing, stats } = usePage<PaywallPageProps>().props;
    const planEntries = Object.entries(pricing.plans);

    if (planEntries.length === 0) {
        return null;
    }

    return (
        <>
            <Head title="Start Your Financial Journey" />

            <div className="flex min-h-screen flex-col items-center justify-center bg-background px-4 py-8">
                <div className="flex w-full max-w-md flex-col gap-6">
                    <div className="flex flex-col items-center gap-4 text-center">
                        <div className="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                            <AppLogoIcon className="h-8 w-8 text-emerald-600 dark:text-emerald-400" />
                        </div>

                        <SocialProofSlider />
                    </div>

                    <FinancialSnapshot stats={stats} />

                    <PricingSection
                        planEntries={planEntries}
                        defaultPlan={pricing.defaultPlan}
                    />

                    {pricing.promo.enabled && <PromoSection />}
                </div>
            </div>
        </>
    );
}
