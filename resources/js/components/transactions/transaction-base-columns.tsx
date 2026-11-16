import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { CategoryValue } from '@/components/shared/category-combobox';
import { LabelBadges } from '@/components/shared/label-combobox';
import {
    buildSplitBreakdown,
    SplitBreakdown,
} from '@/components/transactions/split-breakdown';
import { StatementPaymentNote } from '@/components/transactions/statement-payment-note';
import {
    ENCRYPTED_PLACEHOLDER,
    TransactionBadge,
    TransactionDescription,
} from '@/components/transactions/transaction-description';
import { AmountDisplay } from '@/components/ui/amount-display';
import { resolveTransactionLabels } from '@/lib/transaction-labels';
import { type CreditCardPayment } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { type ColumnDef } from '@tanstack/react-table';
import { type ReactNode } from 'react';

/*
 * The columns every transactions table draws, whatever it lets its rows do.
 * Kept apart from the editable cells and row menus (transaction-columns.tsx)
 * so a list that is only read, as in the chat client's widget preview, pulls
 * in none of the app they need.
 */

export type TransactionColumn = ColumnDef<DecryptedTransaction>;

/** The Category column, its cell drawn by `renderCell`: a picker or only the value. */
export function categoryColumn(
    renderCell: (transaction: DecryptedTransaction) => ReactNode,
): TransactionColumn {
    return {
        accessorKey: 'category_id',
        meta: {
            label: __('Category'),
            cellClassName:
                'pl-2 max-w-[170px] !sm:max-w-[170px] md:max-w-[190px] !min-w-[170px] whitespace-normal',
        },
        header: () => __('Category'),
        cell: ({ row }) => renderCell(row.original),
        enableHiding: true,
    };
}

/**
 * How a row reads, apart from what it holds: a copy with, say, the
 * description rewritten, drawn while edits still act on the row itself.
 */
export type DisplayRow = (
    transaction: DecryptedTransaction,
) => DecryptedTransaction;

const asHeld: DisplayRow = (transaction) => transaction;

export function accountColumn(
    displayRow: DisplayRow = asHeld,
): TransactionColumn {
    return {
        id: 'account',
        accessorKey: 'account',
        header: () => __('Account'),
        meta: {
            label: __('Account'),
            cellClassName: '!min-w-[125px] whitespace-normal',
        },
        cell: ({ row }) => {
            const transaction = displayRow(row.original);
            if (!transaction.account) {
                return <div className="text-muted-foreground">—</div>;
            }

            return (
                <div className="flex items-center gap-2">
                    <BankLogo
                        src={transaction.bank?.logo}
                        name={transaction.bank?.name}
                        className="h-5 w-5"
                    />
                    <AccountName
                        account={transaction.account}
                        length={{ min: 5, max: 15 }}
                        className="truncate"
                    />
                </div>
            );
        },
        enableHiding: true,
    };
}

export interface DescriptionColumnOptions {
    labels: Label[];
    hiddenLabelId?: string;
    statementPayments?: Map<string, CreditCardPayment>;
    /** A short tag after a row's description, or nothing. */
    badgeOf?: (transaction: DecryptedTransaction) => string | null | undefined;
    displayRow?: DisplayRow;
}

export function descriptionColumn({
    labels,
    hiddenLabelId,
    statementPayments,
    badgeOf,
    displayRow = asHeld,
}: DescriptionColumnOptions): TransactionColumn {
    return {
        accessorKey: 'decryptedDescription',
        meta: {
            label: __('Description'),
            cellClassName:
                'max-w-[200px] sm:max-w-[250px] md:max-w-[300px] md:w-full md:min-w-0 md:overflow-hidden whitespace-normal',
        },
        header: () => __('Description'),
        cell: ({ row, table }) => {
            const transaction = displayRow(row.original);
            const columnVisibility = table.getState().columnVisibility;

            const showLabels = columnVisibility.labels !== false;
            const showNotes = columnVisibility.notes !== false;

            const transactionLabels = resolveTransactionLabels(
                transaction,
                labels,
                hiddenLabelId,
            );

            const hasLabels = transactionLabels.length > 0;
            const hasNotes = !!transaction.notes;
            const statementPayment = statementPayments?.get(transaction.id);
            const badge = badgeOf?.(row.original);

            return (
                <div className="flex flex-col gap-0.5">
                    <div className="flex flex-row justify-between gap-1">
                        <div className="flex-grow truncate">
                            <TransactionDescription
                                text={transaction.description}
                                encrypted={!!transaction.description_iv}
                            />
                        </div>
                        {badge && <TransactionBadge text={badge} />}
                        {showLabels && hasLabels && (
                            <LabelBadges labels={transactionLabels} max={3} />
                        )}
                    </div>
                    {statementPayment && (
                        <StatementPaymentNote payment={statementPayment} />
                    )}
                    {showNotes && hasNotes && (
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                            <div className="truncate text-muted-foreground/80">
                                <span>
                                    {transaction.notes_iv
                                        ? ENCRYPTED_PLACEHOLDER
                                        : transaction.notes}
                                </span>
                            </div>
                        </div>
                    )}
                </div>
            );
        },
        enableHiding: false,
    };
}

export function amountColumn(): TransactionColumn {
    return {
        accessorKey: 'amount',
        meta: { label: __('Amount') },
        header: () => {
            return <div className="w-full text-right">{__('Amount')}</div>;
        },
        cell: ({ row }) => {
            const amountInCents = row.getValue('amount') as number;
            const currencyCode = row.original.currency_code;

            return (
                <div className="pl-4 text-right">
                    <AmountDisplay
                        amountInCents={amountInCents}
                        currencyCode={currencyCode}
                        variant="positive-highlight"
                        highlightPositive={amountInCents >= 0}
                        monospace
                    />
                </div>
            );
        },
        enableHiding: true,
    };
}

/** Virtual columns for visibility control only (not rendered as table columns). */
export function visibilityColumns(): TransactionColumn[] {
    return [
        {
            id: 'labels',
            accessorKey: 'label_ids',
            meta: { label: __('Labels'), isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
        {
            id: 'notes',
            accessorKey: 'decryptedNotes',
            meta: { label: __('Notes'), isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
    ];
}

/**
 * The Category cell as it reads, with nothing to pick: what `CategoryCell`
 * draws in its closed picker, or a split transaction's lines, for a table
 * whose rows are not edited in place.
 */
function ReadOnlyCategoryCell({
    transaction,
    categories,
}: {
    transaction: DecryptedTransaction;
    categories: Category[];
}) {
    if (transaction.splits?.length) {
        return (
            <div
                className="w-full max-w-[150px] md:max-w-[180px]"
                data-testid="row-category"
            >
                <SplitBreakdown
                    lines={buildSplitBreakdown(transaction.splits)}
                    count={transaction.splits.length}
                    currencyCode={transaction.currency_code}
                />
            </div>
        );
    }

    const category =
        categories.find((c) => c.id === transaction.category_id) ?? null;

    return (
        <div
            className="relative -top-0.5 flex w-full max-w-[150px] items-center pr-2 pl-1 text-sm font-medium md:max-w-[180px]"
            data-testid="row-category"
        >
            <CategoryValue category={category} />
        </div>
    );
}

/**
 * The table's columns for a list that is only read: no checkbox, no row
 * menu and a Category cell with nothing to pick. Each row still opens, so
 * what to do with it is the row's to say.
 */
export function createReadOnlyTransactionColumns({
    categories,
    labels,
    hiddenLabelId,
    badgeOf,
}: Pick<DescriptionColumnOptions, 'labels' | 'hiddenLabelId' | 'badgeOf'> & {
    categories: Category[];
}): ColumnDef<DecryptedTransaction>[] {
    return [
        categoryColumn((transaction) => (
            <ReadOnlyCategoryCell
                transaction={transaction}
                categories={categories}
            />
        )),
        accountColumn(),
        descriptionColumn({
            labels,
            hiddenLabelId,
            badgeOf,
        }),
        amountColumn(),
        ...visibilityColumns(),
    ];
}
