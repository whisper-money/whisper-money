import { type SystemWidgetId } from '@/components/dashboard/widgets/registry';
import { PrivacyRevealScope } from '@/contexts/privacy-mode-context';
import { type FirstDashboardState } from '@/types/dashboard';
import { type ReactNode } from 'react';
import { CurrentAccountNeededCard } from './current-account-needed-card';
import { EstimatedSpendingCard } from './estimated-spending-card';
import { NoMovementsCashflowCard } from './no-movements-cashflow-card';

/**
 * What the system dashboard draws instead of its spending and cashflow
 * widgets while there is no real movement; null hides a widget. The other
 * widgets stay as they are.
 *
 * - Only estimates: the month typed in, labelled as such, where the top
 *   categories go, and a cashflow card that waits for the first movement.
 * - Only balances: one card in place of both, saying they need a current
 *   account.
 */
export function firstDashboardReplacements(
    state: FirstDashboardState,
    showsCashflow: boolean,
): Partial<Record<SystemWidgetId, ReactNode>> {
    if (state.state === 'balances') {
        return {
            'top-categories': (
                <PrivacyRevealScope>
                    <CurrentAccountNeededCard
                        spendingGuess={state.spending_guess}
                        currencyCode={state.currency_code}
                        canImport={state.can_import}
                    />
                </PrivacyRevealScope>
            ),
            cashflow: null,
        };
    }

    return {
        'top-categories': (
            <PrivacyRevealScope>
                <EstimatedSpendingCard
                    estimates={state.estimates}
                    spendingGuess={state.spending_guess}
                    guessCurrencyCode={state.currency_code}
                />
            </PrivacyRevealScope>
        ),
        cashflow: showsCashflow ? (
            <NoMovementsCashflowCard yearMonth={state.today.slice(0, 7)} />
        ) : null,
    };
}
