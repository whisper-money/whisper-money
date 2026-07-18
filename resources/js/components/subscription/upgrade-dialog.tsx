import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { captureEvent } from '@/lib/posthog';
import { cn } from '@/lib/utils';
import { checkout } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { ZapIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * The upsell entry point a checkout starts from. Mirrors the PHP
 * App\Enums\UpsellSource so revenue can be attributed per point.
 */
export type UpsellSource = 'ai_categorization' | 'connections' | 'accounts';

export function PlanCard({
    plan,
    isSelected,
    onSelect,
    currency,
    locale,
}: {
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

/**
 * A contextual "this is a paid feature" dialog with a plan picker that starts
 * Stripe checkout. Reused across upsell points (AI categorization, bank
 * connections, connected accounts); each passes its own copy and `source`.
 */
export function UpgradeDialog({
    open,
    onOpenChange,
    title,
    description,
    source,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    source: UpsellSource;
}) {
    const { pricing, locale } = usePage<SharedData>().props;
    const planEntries = Object.entries(pricing.plans);
    const [selectedPlan, setSelectedPlan] = useState(pricing.defaultPlan);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>

                <div className="flex gap-3">
                    {planEntries.map(([key, plan]) => (
                        <PlanCard
                            key={key}
                            plan={plan}
                            isSelected={key === selectedPlan}
                            onSelect={() => setSelectedPlan(key)}
                            currency={pricing.currency}
                            locale={locale}
                        />
                    ))}
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        {__('Maybe later')}
                    </Button>
                    <a
                        href={checkout.url({
                            query: { plan: selectedPlan, source },
                        })}
                        onClick={() =>
                            captureEvent('upgrade_checkout_started', {
                                source,
                                plan: selectedPlan,
                            })
                        }
                    >
                        <Button className="w-full bg-emerald-600 hover:bg-emerald-700 dark:bg-emerald-600 dark:hover:bg-emerald-700">
                            <ZapIcon className="size-4" />
                            {__('Upgrade to Standard Plan')}
                        </Button>
                    </a>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
