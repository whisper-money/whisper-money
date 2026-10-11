import { type SharedData } from '@/types';
import { type Account } from '@/types/account';
import { usePage } from '@inertiajs/react';
import { useCallback } from 'react';

type BalanceAccount = Pick<Account, 'type' | 'banking_connection_id'>;

/**
 * Whether a transaction may move this account's balance. A connected
 * account's balance is the bank's to report, and a credit card under the
 * credit card feature has a limit and what is in use of it, never a balance.
 * Mirrors ManualBalanceAdjuster::ownsBalance() on the server.
 */
export function ownsBalanceUpdates(
    account: BalanceAccount,
    creditCardStatements: boolean,
): boolean {
    if (account.banking_connection_id) {
        return false;
    }

    return !(account.type === 'credit_card' && creditCardStatements);
}

/**
 * ownsBalanceUpdates() bound to the current user's credit card feature.
 */
export function useOwnsBalanceUpdates(): (account: BalanceAccount) => boolean {
    const { features } = usePage<SharedData>().props;
    const creditCardStatements = Boolean(features?.creditCardStatements);

    return useCallback(
        (account) => ownsBalanceUpdates(account, creditCardStatements),
        [creditCardStatements],
    );
}
