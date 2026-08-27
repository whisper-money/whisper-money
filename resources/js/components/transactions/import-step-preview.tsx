import { index as transactionsIndex } from '@/actions/App/Http/Controllers/Api/TransactionController';
import {
    CollapsibleSection,
    FOOTNOTE,
    HEAD_CELL,
} from '@/components/transactions/import-section';
import { TransactionDescription } from '@/components/transactions/transaction-description';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useLocale } from '@/hooks/use-locale';
import { type ParsedTransaction } from '@/types/import';
import { type Transaction } from '@/types/transaction';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import type { ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';

/**
 * The table's own shape, shared by the header and every row so the columns line
 * up. One row per transaction on a phone — checkbox, then description and
 * amount over date and balance — and the table proper from md up.
 */
const rowGrid = (hasBalances: boolean) =>
    `grid grid-cols-[1.25rem_minmax(0,1fr)_auto] items-center gap-x-3 gap-y-0.5 md:gap-x-4 md:gap-y-0 ${
        hasBalances
            ? 'md:grid-cols-[1.25rem_7.5rem_minmax(0,1fr)_7rem_7rem]'
            : 'md:grid-cols-[1.25rem_7.5rem_minmax(0,1fr)_7rem]'
    }`;

interface ImportStepPreviewProps {
    transactions: ParsedTransaction[];
    currencyCode: string;
    accountId: string;
    onConfirm: () => void;
    onBack: () => void;
    onSelectionChange: (index: number, selected: boolean) => void;
    onSelectAll: (selected: boolean) => void;
    isImporting: boolean;
}

/** A row kept with its index in the full list, which selection is keyed by. */
interface IndexedTransaction {
    transaction: ParsedTransaction;
    index: number;
}

interface CompactRow {
    key: string;
    date: string;
    description: ReactNode;
    amountInCents: number;
    currencyCode: string;
}

/** Date, description, amount: the shape both folded-away tables take. */
function CompactTransactionTable({
    rows,
    locale,
}: {
    rows: CompactRow[];
    locale: string;
}) {
    return (
        <table className="w-full text-[13px] text-muted-foreground">
            <thead>
                <tr className="bg-muted">
                    <th className={`px-4 py-2 text-left ${HEAD_CELL}`}>
                        {__('Date')}
                    </th>
                    <th className={`px-4 py-2 text-left ${HEAD_CELL}`}>
                        {__('Description')}
                    </th>
                    <th className={`px-4 py-2 text-right ${HEAD_CELL}`}>
                        {__('Amount')}
                    </th>
                </tr>
            </thead>
            <tbody>
                {rows.map((row) => (
                    <tr key={row.key} className="border-t">
                        <td className="px-4 py-2 font-mono whitespace-nowrap">
                            {formatDateMedium(row.date, locale)}
                        </td>
                        <td className="max-w-[220px] truncate px-4 py-2">
                            {row.description}
                        </td>
                        <td className="px-4 py-2 text-right">
                            <AmountDisplay
                                amountInCents={row.amountInCents}
                                currencyCode={row.currencyCode}
                                monospace
                            />
                        </td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

export function ImportStepPreview({
    transactions,
    currencyCode,
    accountId,
    onConfirm,
    onBack,
    onSelectionChange,
    onSelectAll,
    isImporting,
}: ImportStepPreviewProps) {
    const locale = useLocale();
    const [existingTransactions, setExistingTransactions] = useState<
        Transaction[]
    >([]);
    const [existingOpen, setExistingOpen] = useState(false);
    const [duplicatesOpen, setDuplicatesOpen] = useState(false);

    useEffect(() => {
        if (!accountId) {
            return;
        }

        axios
            .get(
                transactionsIndex.url({
                    query: { account_id: accountId, per_page: 10 },
                }),
            )
            .then((response) => {
                setExistingTransactions(response.data.data ?? []);
            })
            .catch((error) => {
                console.error('Failed to load existing transactions:', error);
                setExistingTransactions([]);
            });
    }, [accountId]);

    const {
        importable,
        duplicates,
        selectedCount,
        allSelected,
        someSelected,
        hasBalances,
        dateRange,
        net,
        netCurrency,
    } = useMemo(() => {
        const importable: IndexedTransaction[] = [];
        const duplicates: ParsedTransaction[] = [];

        transactions.forEach((transaction, index) => {
            if (transaction.isDuplicate) {
                duplicates.push(transaction);
            } else {
                importable.push({ transaction, index });
            }
        });

        const selected = importable.filter(
            ({ transaction }) => transaction.selected,
        );
        const dates = selected
            .map(({ transaction }) => transaction.transaction_date)
            .sort();
        const currencies = new Set(
            selected.map(
                ({ transaction }) => transaction.currency_code ?? currencyCode,
            ),
        );

        return {
            importable,
            duplicates,
            selectedCount: selected.length,
            allSelected:
                importable.length > 0 && selected.length === importable.length,
            someSelected:
                selected.length > 0 && selected.length < importable.length,
            hasBalances: importable.some(
                ({ transaction }) =>
                    transaction.balance !== null &&
                    transaction.balance !== undefined,
            ),
            dateRange:
                dates.length > 0
                    ? { from: dates[0], to: dates[dates.length - 1] }
                    : null,
            // A mapped currency column leaves the rows in several currencies,
            // and those have no one net to report.
            net:
                currencies.size === 1
                    ? selected.reduce(
                          (total, { transaction }) =>
                              total + transaction.amount,
                          0,
                      )
                    : null,
            netCurrency: [...currencies][0] ?? currencyCode,
        };
    }, [transactions, currencyCode]);

    const grid = rowGrid(hasBalances);

    const dateRangeLabel = dateRange
        ? dateRange.from === dateRange.to
            ? formatDateMedium(dateRange.from, locale)
            : `${formatDateMedium(dateRange.from, locale)} → ${formatDateMedium(dateRange.to, locale)}`
        : '';

    return (
        <div className="flex flex-col gap-4">
            {/* What will be imported */}
            <div className="overflow-hidden rounded-lg border">
                <div className={`${grid} min-h-11 bg-muted px-4 py-2.5`}>
                    <Checkbox
                        checked={someSelected ? 'indeterminate' : allSelected}
                        onCheckedChange={(checked) =>
                            onSelectAll(checked === true)
                        }
                        disabled={importable.length === 0}
                        aria-label={__('Select all transactions')}
                        className="self-center"
                    />
                    <span
                        className={`${HEAD_CELL} col-start-2 row-start-1 md:col-start-3`}
                    >
                        {__('Description')}
                    </span>
                    <span
                        className={`${HEAD_CELL} col-start-3 row-start-1 text-right md:col-start-4`}
                    >
                        {__('Amount')}
                    </span>
                    <span
                        className={`${HEAD_CELL} hidden md:col-start-2 md:row-start-1 md:block`}
                    >
                        {__('Date')}
                    </span>
                    {hasBalances && (
                        <span
                            className={`${HEAD_CELL} hidden text-right md:col-start-5 md:row-start-1 md:block`}
                        >
                            {__('Balance')}
                        </span>
                    )}
                </div>

                <div className="max-h-[45vh] overflow-auto">
                    {importable.length === 0 ? (
                        <p className="border-t px-4 py-6 text-center text-sm text-muted-foreground">
                            {__('No valid transactions found')}
                        </p>
                    ) : (
                        importable.map(({ transaction, index }) => (
                            <div
                                key={index}
                                className={`${grid} min-h-11 border-t px-4 py-2.5 text-[13px] ${
                                    transaction.selected
                                        ? ''
                                        : 'bg-muted/30 text-muted-foreground'
                                }`}
                            >
                                <Checkbox
                                    checked={transaction.selected ?? false}
                                    onCheckedChange={(checked) =>
                                        onSelectionChange(
                                            index,
                                            checked === true,
                                        )
                                    }
                                    aria-label={__('Select :description', {
                                        description: transaction.description,
                                    })}
                                    className="row-span-2 self-center md:row-span-1"
                                />
                                <span className="col-start-2 row-start-1 truncate md:col-start-3">
                                    {transaction.description}
                                </span>
                                <span className="col-start-3 row-start-1 text-right md:col-start-4">
                                    <AmountDisplay
                                        amountInCents={transaction.amount}
                                        currencyCode={
                                            transaction.currency_code ??
                                            currencyCode
                                        }
                                        variant="positive-highlight"
                                        highlightPositive={
                                            transaction.amount >= 0
                                        }
                                        monospace
                                    />
                                </span>
                                <span className="col-start-2 row-start-2 text-xs whitespace-nowrap text-muted-foreground tabular-nums md:col-start-2 md:row-start-1 md:text-[13px] md:text-inherit">
                                    {formatDateMedium(
                                        transaction.transaction_date,
                                        locale,
                                    )}
                                </span>
                                {hasBalances && (
                                    <span className="col-start-3 row-start-2 text-right text-xs text-muted-foreground md:col-start-5 md:row-start-1 md:text-[13px]">
                                        {transaction.balance !== null &&
                                        transaction.balance !== undefined ? (
                                            <AmountDisplay
                                                amountInCents={
                                                    transaction.balance
                                                }
                                                currencyCode={currencyCode}
                                                monospace
                                            />
                                        ) : (
                                            '—'
                                        )}
                                    </span>
                                )}
                            </div>
                        ))
                    )}
                </div>

                <div
                    className={`${FOOTNOTE} flex flex-wrap items-center justify-between gap-x-4 gap-y-1`}
                >
                    <span className="tabular-nums">{dateRangeLabel}</span>
                    <span className="flex items-center gap-3">
                        {net !== null && (
                            <span>
                                {__('Net')}{' '}
                                <AmountDisplay
                                    amountInCents={net}
                                    currencyCode={netCurrency}
                                    showSign
                                    monospace
                                />
                            </span>
                        )}
                        <span>
                            {__(':count of :total selected', {
                                count: selectedCount,
                                total: importable.length,
                            })}
                        </span>
                    </span>
                </div>
            </div>

            {/* Already here, so out of the list above */}
            {duplicates.length > 0 && (
                <CollapsibleSection
                    open={duplicatesOpen}
                    onOpenChange={setDuplicatesOpen}
                    className="border-sidebar-border bg-sidebar"
                    hint={__('Show them')}
                    title={
                        <>
                            <span className="text-sm font-medium">
                                {duplicates.length === 1
                                    ? __(
                                          ':count row is already in this account',
                                          { count: duplicates.length },
                                      )
                                    : __(
                                          ':count rows are already in this account',
                                          { count: duplicates.length },
                                      )}
                            </span>
                            <span className="rounded-md border bg-muted px-1.5 text-xs font-medium">
                                {__("Won't be imported")}
                            </span>
                        </>
                    }
                >
                    <div className="max-h-[35vh] overflow-auto">
                        <CompactTransactionTable
                            locale={locale}
                            rows={duplicates.map((transaction, index) => ({
                                key: `${index}`,
                                date: transaction.transaction_date,
                                description: transaction.description,
                                amountInCents: transaction.amount,
                                currencyCode:
                                    transaction.currency_code ?? currencyCode,
                            }))}
                        />
                    </div>
                    <p className={FOOTNOTE}>
                        {__(
                            'Matched on date, description and amount. Re-upload without them, or carry on — they will be left as they are.',
                        )}
                    </p>
                </CollapsibleSection>
            )}

            {/* Folded away until asked for */}
            {existingTransactions.length > 0 && (
                <CollapsibleSection
                    open={existingOpen}
                    onOpenChange={setExistingOpen}
                    className="border-sidebar-border bg-sidebar"
                    contentClassName="border-sidebar-border bg-background"
                    title={
                        <span className="text-sm font-medium">
                            {__('Latest transactions in this account')}
                        </span>
                    }
                >
                    <div className="max-h-[250px] overflow-auto">
                        <CompactTransactionTable
                            locale={locale}
                            rows={existingTransactions.map((transaction) => ({
                                key: transaction.id,
                                date: transaction.transaction_date,
                                description: (
                                    <TransactionDescription
                                        text={transaction.description}
                                    />
                                ),
                                amountInCents: transaction.amount,
                                currencyCode: transaction.currency_code,
                            }))}
                        />
                    </div>
                </CollapsibleSection>
            )}

            <div className="flex items-center justify-between pt-2">
                <Button
                    variant="outline"
                    onClick={onBack}
                    disabled={isImporting}
                >
                    {__('Back')}
                </Button>
                <div className="flex items-center gap-3">
                    <span className="hidden text-xs text-muted-foreground sm:block">
                        {__(':count of :total rows ready', {
                            count: selectedCount,
                            total: importable.length,
                        })}
                    </span>
                    <Button
                        onClick={onConfirm}
                        disabled={isImporting || selectedCount === 0}
                    >
                        {isImporting
                            ? __('Importing...')
                            : selectedCount === 1
                              ? __('Import :count transaction', {
                                    count: selectedCount,
                                })
                              : __('Import :count transactions', {
                                    count: selectedCount,
                                })}
                    </Button>
                </div>
            </div>
        </div>
    );
}
