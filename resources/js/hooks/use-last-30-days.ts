import { usePage } from '@inertiajs/react';

interface SpendingWindowProps {
    [key: string]: unknown;
    spendingWindow: { from: string; to: string };
}

/**
 * The trailing 30-day window the dashboard spending cards report on, as
 * `yyyy-MM-dd` bounds ready to hand to a transactions filter. The server picks
 * it on the user's own date and sums the cards over it, so the links and
 * drill-downs read it from there instead of the browser's clock.
 */
export function useLast30Days(): { dateFrom: string; dateTo: string } {
    const { spendingWindow } = usePage<SpendingWindowProps>().props;

    return { dateFrom: spendingWindow.from, dateTo: spendingWindow.to };
}
