import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { checkout } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { Head, usePage } from '@inertiajs/react';
import { CheckIcon } from 'lucide-react';

function getBillingLabel(billingPeriod: string | null): string {
    if (!billingPeriod) {
        return 'one-time';
    }
    return `/${billingPeriod}`;
}

function PlanCard({
    planKey,
    plan,
    isDefault,
    isBestValue,
}: {
    planKey: string;
    plan: Plan;
    isDefault: boolean;
    isBestValue: boolean;
}) {
    return (
        <div
            className={cn(
                'relative flex flex-col overflow-hidden rounded-xl border bg-card',
                isDefault && 'border-emerald-500 ring-2 ring-emerald-500',
                isBestValue && 'border-blue-500 shadow-xl ring-1 ring-blue-500',
            )}
        >
            {isDefault && (
                <div className="bg-emerald-500 p-2 text-center text-xs font-medium text-white">
                    Most Popular
                </div>
            )}
            {isBestValue && (
                <div className="bg-blue-50 p-2 text-center text-xs font-medium text-blue-500">
                    Best Value
                </div>
            )}
            <div className="flex flex-1 flex-col p-5">
                <h3 className="text-lg font-semibold">{plan.name}</h3>
                <div className="mt-2 flex items-baseline gap-1">
                    {plan.original_price && (
                        <span className="text-sm text-muted-foreground line-through">
                            ${plan.original_price}
                        </span>
                    )}
                    <span className="text-3xl font-bold">${plan.price}</span>
                    <span className="text-sm text-muted-foreground">
                        {getBillingLabel(plan.billing_period)}
                    </span>
                </div>

                <ul className="mt-4 flex-1 space-y-2">
                    {plan.features.map((feature) => (
                        <li key={feature} className="flex items-center gap-2">
                            <CheckIcon className="size-4 shrink-0 text-emerald-500" />
                            <span className="text-sm">{feature}</span>
                        </li>
                    ))}
                </ul>

                <a href={checkout.url({ plan: planKey })} className="mt-6">
                    <Button
                        className={cn(
                            'w-full cursor-pointer',
                            isDefault &&
                                'bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-700',
                        )}
                        variant={isDefault ? 'default' : 'outline'}
                    >
                        {plan.billing_period ? 'Subscribe' : 'Buy Now'}
                    </Button>
                </a>
            </div>
        </div>
    );
}

export default function Paywall() {
    const { pricing } = usePage<SharedData>().props;
    const planEntries = Object.entries(pricing.plans);

    if (planEntries.length === 0) {
        return null;
    }

    return (
        <>
            <Head title="Upgrade to Pro" />

            <div className="flex min-h-screen flex-col items-center justify-center bg-background px-4 py-12">
                <div className="w-full max-w-4xl">
                    <div className="mb-8 text-center">
                        <h1 className="text-3xl font-bold">Upgrade to Pro</h1>
                        <p className="mt-2 text-muted-foreground">
                            Unlock all features and take control of your
                            finances
                        </p>
                    </div>

                    <div
                        className={cn(
                            'grid gap-4',
                            planEntries.length === 1 && 'mx-auto max-w-sm',
                            planEntries.length === 2 &&
                                'mx-auto max-w-2xl grid-cols-1 sm:grid-cols-2',
                            planEntries.length >= 3 &&
                                'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3',
                        )}
                    >
                        {planEntries.map(([key, plan]) => (
                            <PlanCard
                                key={key}
                                planKey={key}
                                plan={plan}
                                isDefault={key === pricing.defaultPlan}
                                isBestValue={key === pricing.bestValuePlan}
                            />
                        ))}
                    </div>

                    {pricing.promo.enabled && (
                        <p className="mt-6 text-center text-sm text-muted-foreground">
                            🎉 Get a founder discount •{' '}
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger asChild>
                                        <a
                                            href="https://discord.gg/9UQWZECDDv"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="font-semibold text-[#5865F2] underline-offset-2 hover:underline"
                                        >
                                            Join our Discord
                                        </a>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        You'll receive an exclusive promo code
                                        via DM!
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
