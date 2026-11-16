import {
    type AmountInUserCurrency,
    type DatedAmount,
} from '@/lib/transaction-day-groups';
import { usePage } from './inertia-page';

/**
 * `@/hooks/use-amounts-in-user-currency` in the widget preview build
 * (vite.widget-preview.config.ts aliases it here). The chat client's iframe
 * must send nothing anywhere, least of all the user's amounts: an amount
 * already in the user's currency is itself, any other one has no rate, so a
 * day holding one shows its total as missing rather than asking for it.
 */
export function useAmountsInUserCurrency(): AmountInUserCurrency {
    const userCurrency = usePage().props.auth.user.currency_code.toUpperCase();

    return (amount: DatedAmount) =>
        amount.currency_code.toUpperCase() === userCurrency
            ? amount.amount
            : null;
}
