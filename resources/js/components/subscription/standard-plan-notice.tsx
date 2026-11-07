import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { billing } from '@/routes/settings';
import type { SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { Lock } from 'lucide-react';

/**
 * Whether the user pays a plan that leaves the bank aggregator out (latam).
 * Their banks show locked behind the standard plan while the direct
 * integrations (brokers, exchanges, Wise) stay open. Free users get `false`:
 * they meet the regular paywall instead.
 */
export function useBankAggregatorLocked(): boolean {
    return usePage<SharedData>().props.auth?.bankAggregatorLocked ?? false;
}

/** Whether a connection or provider goes through the bank aggregator. */
export function isBankAggregatorProvider(provider: string): boolean {
    return provider === 'enablebanking';
}

/**
 * Tells a latam subscriber that banks need the standard plan, and leads to
 * billing, where the plan is changed.
 */
export function StandardPlanNotice({ message }: { message: string }) {
    return (
        <Alert className="border-amber-500/20 bg-amber-500/10 text-amber-700 dark:text-amber-300">
            <Lock />
            <AlertDescription className="flex flex-wrap items-center justify-between gap-3 text-amber-700 dark:text-amber-300">
                <span>{message}</span>
                <Button
                    asChild
                    size="sm"
                    variant="secondary"
                    className="h-7 text-xs"
                >
                    <Link href={billing.url()}>{__('Change plan')}</Link>
                </Button>
            </AlertDescription>
        </Alert>
    );
}
