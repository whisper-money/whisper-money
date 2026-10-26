import { TransactionTable } from '@/components/transactions/transaction-table';
import { useInMemoryTransactionSource } from '@/hooks/use-in-memory-transaction-source';
import { reloadPage } from '@/lib/leave-page';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { type ReactNode } from 'react';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { TransactionActionsMenu } from '@/components/transactions/transaction-actions-menu';
import { Button } from '@/components/ui/button';
import {
    filterTransactionalAccounts,
    type Account,
    type Bank,
    type CreditCardPayment,
} from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type Transaction } from '@/types/transaction';
import { UUID } from '@/types/uuid';

const NO_LABELS: Label[] = [];
const NO_RULES: AutomationRule[] = [];

export interface TransactionListProps {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels?: Label[];
    automationRules?: AutomationRule[];
    accountId?: UUID;
    transactions?: Transaction[]; // Server-provided transactions to render
    pageSize?: number;
    hideAccountFilter?: boolean;
    showActionsMenu?: boolean;
    headerActions?: ReactNode;
    maxHeight?: number;
    /** Inside a card: keep the frame on a phone instead of running edge to edge. */
    framedOnPhone?: boolean;
    hideColumns?: string[];
    onBalanceUpdated?: () => void;
    /**
     * Called after a transaction in the list is edited or deleted, for pages
     * whose figures are computed from the ledger on the server.
     */
    onTransactionsChanged?: () => void;
    /** A label every listed transaction already carries; hidden from the rows. */
    hiddenLabelId?: UUID;
    /** Credit card repayments by transaction id, with the statement each pays. */
    statementPayments?: Map<string, CreditCardPayment>;
}

export function TransactionList({
    categories,
    accounts,
    banks,
    labels,
    automationRules = NO_RULES,
    accountId,
    transactions,
    pageSize = 25,
    hideAccountFilter = false,
    showActionsMenu = true,
    headerActions,
    maxHeight,
    framedOnPhone,
    hideColumns,
    onBalanceUpdated,
    onTransactionsChanged,
    hiddenLabelId,
    statementPayments,
}: TransactionListProps) {
    const source = useInMemoryTransactionSource({
        transactions,
        accountId,
        pageSize,
    });

    return (
        <TransactionTable
            source={source}
            categories={categories}
            accounts={accounts}
            banks={banks}
            labels={labels ?? NO_LABELS}
            automationRules={automationRules}
            hideAccountFilter={hideAccountFilter}
            hideColumns={hideColumns}
            hiddenLabelId={hiddenLabelId}
            statementPayments={statementPayments}
            maxHeight={maxHeight}
            framedOnPhone={framedOnPhone}
            onBalanceUpdated={onBalanceUpdated}
            onTransactionsChanged={onTransactionsChanged}
            showColumnPickerTextOnMobile
            renderToolbar={({ columnPicker, clearSelection }) => (
                <div className="flex flex-wrap justify-end gap-2">
                    {showActionsMenu && (
                        <TransactionActionsMenu
                            transactions={source.heldRows}
                            hasTransactionalAccounts={
                                filterTransactionalAccounts(accounts).length > 0
                            }
                            onReEvaluateComplete={() => {
                                clearSelection();
                                setTimeout(() => {
                                    reloadPage();
                                }, 500);
                            }}
                        />
                    )}
                    {headerActions}
                    {columnPicker}
                </div>
            )}
            footerActions={
                accountId && (
                    <Button asChild variant="outline">
                        <Link
                            href={
                                transactionsIndex({
                                    query: { account_ids: accountId },
                                }).url
                            }
                        >
                            <ExternalLink />
                            {__('View in Transactions')}
                        </Link>
                    </Button>
                )
            }
        />
    );
}
