import { type Account } from '@/types/account';
import { useMemo } from 'react';

type BalanceAccount = Pick<Account, 'type' | 'banking_connection_id'>;
type IdentifiedBalanceAccount = BalanceAccount & Pick<Account, 'id'>;

/**
 * Whether a transaction may move this account's balance. A connected
 * account's balance is the bank's to report, and a credit card has a limit
 * and what is in use of it, never a balance. Mirrors
 * ManualBalanceAdjuster::ownsBalance() on the server.
 */
export function ownsBalanceUpdates(account: BalanceAccount): boolean {
    return !account.banking_connection_id && account.type !== 'credit_card';
}

/**
 * The ids of the accounts among $accounts whose balance a transaction may
 * move, for the delete flows that offer to update it.
 */
export function useBalanceOwningAccountIds(
    accounts: IdentifiedBalanceAccount[],
): Set<string> {
    return useMemo(
        () =>
            new Set(
                accounts
                    .filter((account) => ownsBalanceUpdates(account))
                    .map((account) => account.id),
            ),
        [accounts],
    );
}
