import { TransactionDescription } from '@/components/transactions/transaction-description';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLocale } from '@/hooks/use-locale';
import { transactionSyncService } from '@/services/transaction-sync';
import { type ParsedTransaction } from '@/types/import';
import { type Transaction } from '@/types/transaction';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { ChevronDown } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
    const [isExistingOpen, setIsExistingOpen] = useState(false);

    useEffect(() => {
        if (accountId) {
            transactionSyncService.getByAccountId(accountId).then((txns) => {
                const sorted = txns.sort(
                    (a, b) =>
                        new Date(b.transaction_date).getTime() -
                        new Date(a.transaction_date).getTime(),
                );
                setExistingTransactions(sorted.slice(0, 10));
            });
        }
    }, [accountId]);

    const stats = useMemo(() => {
        const selectableTransactions = transactions.filter(
            (t) => !t.isDuplicate,
        );
        const selectedCount = selectableTransactions.filter(
            (t) => t.selected,
        ).length;
        const duplicateCount = transactions.filter((t) => t.isDuplicate).length;
        const allSelected =
            selectableTransactions.length > 0 &&
            selectedCount === selectableTransactions.length;
        const someSelected = selectedCount > 0 && !allSelected;

        return {
            selectedCount,
            duplicateCount,
            total: transactions.length,
            selectableCount: selectableTransactions.length,
            allSelected,
            someSelected,
        };
    }, [transactions]);

    const handleHeaderCheckboxChange = (checked: boolean) => {
        onSelectAll(checked);
    };

    const hasBalances = useMemo(
        () =>
            transactions.some(
                (t) => t.balance !== null && t.balance !== undefined,
            ),
        [transactions],
    );

    return (
        <div className="flex flex-col gap-6">
            <div className="flex gap-4 rounded-lg border bg-muted/50 p-4">
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">
                        {__('Total')}
                    </p>
                    <p className="text-2xl font-bold">{stats.total}</p>
                </div>
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">
                        {__('Selected')}
                    </p>
                    <p className="text-2xl font-bold text-green-600 dark:text-green-400">
                        {stats.selectedCount}
                    </p>
                </div>
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">
                        {__('Duplicates')}
                    </p>
                    <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {stats.duplicateCount}
                    </p>
                </div>
            </div>

            {stats.selectableCount === 0 && stats.duplicateCount > 0 && (
                <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-4">
                    <p className="text-sm text-amber-700 dark:text-amber-300">
                        {__(
                            'All transactions appear to be duplicates. No new transactions will be imported.',
                        )}
                    </p>
                </div>
            )}

            <div className="max-h-[400px] overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="w-[50px]">
                                <Checkbox
                                    checked={
                                        stats.someSelected
                                            ? 'indeterminate'
                                            : stats.allSelected
                                    }
                                    onCheckedChange={handleHeaderCheckboxChange}
                                    disabled={stats.selectableCount === 0}
                                    aria-label={__('Select all transactions')}
                                />
                            </TableHead>
                            <TableHead className="text-center">
                                {__('Status')}
                            </TableHead>
                            <TableHead>{__('Date')}</TableHead>
                            <TableHead>{__('Description')}</TableHead>
                            <TableHead className="text-right">
                                {__('Amount')}
                            </TableHead>
                            {hasBalances && (
                                <TableHead className="text-right">
                                    {__('Balance')}
                                </TableHead>
                            )}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {transactions.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={hasBalances ? 6 : 5}
                                    className="text-center text-muted-foreground"
                                >
                                    No valid transactions found
                                </TableCell>
                            </TableRow>
                        ) : (
                            transactions.map((transaction, index) => (
                                <TableRow
                                    key={index}
                                    className={
                                        transaction.isDuplicate
                                            ? 'opacity-60'
                                            : ''
                                    }
                                >
                                    <TableCell>
                                        <Checkbox
                                            checked={
                                                transaction.selected ?? false
                                            }
                                            onCheckedChange={(checked) =>
                                                onSelectionChange(
                                                    index,
                                                    checked === true,
                                                )
                                            }
                                            disabled={transaction.isDuplicate}
                                            aria-label={`Select transaction: ${transaction.description}`}
                                        />
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {transaction.isDuplicate ? (
                                            <Badge variant="secondary">
                                                {__('Duplicate')}
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="secondary"
                                                className="bg-green-50 text-green-600 dark:bg-green-900 dark:text-green-600"
                                            >
                                                {__('New')}
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {formatDateMedium(
                                            transaction.transaction_date,
                                            locale,
                                        )}
                                    </TableCell>
                                    <TableCell className="max-w-[200px] truncate">
                                        {transaction.description}
                                    </TableCell>
                                    <TableCell className="text-right font-mono">
                                        <AmountDisplay
                                            amountInCents={transaction.amount}
                                            currencyCode={currencyCode}
                                        />
                                    </TableCell>
                                    {hasBalances && (
                                        <TableCell className="text-right font-mono">
                                            {transaction.balance !== null &&
                                            transaction.balance !==
                                                undefined ? (
                                                <AmountDisplay
                                                    amountInCents={
                                                        transaction.balance
                                                    }
                                                    currencyCode={currencyCode}
                                                />
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            {existingTransactions.length > 0 && (
                <Collapsible
                    open={isExistingOpen}
                    onOpenChange={setIsExistingOpen}
                    className="rounded-lg border border-sidebar-border bg-sidebar p-1"
                >
                    <CollapsibleTrigger asChild>
                        <Button
                            variant="ghost"
                            className="flex w-full cursor-pointer items-center justify-between hover:bg-transparent"
                        >
                            <span className="text-sm text-muted-foreground">
                                {__('Latest transactions in this account')}
                            </span>
                            <ChevronDown
                                className={`h-4 w-4 text-muted-foreground transition-transform duration-200 ${
                                    isExistingOpen ? 'rotate-180' : ''
                                }`}
                            />
                        </Button>
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <div className="mt-3 max-h-[250px] overflow-auto rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{__('Date')}</TableHead>
                                        <TableHead>
                                            {__('Description')}
                                        </TableHead>
                                        <TableHead className="text-right">
                                            {__('Amount')}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {existingTransactions.map((tx) => (
                                        <TableRow key={tx.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatDateMedium(
                                                    tx.transaction_date,
                                                    locale,
                                                )}
                                            </TableCell>
                                            <TableCell className="max-w-[200px] truncate">
                                                <TransactionDescription
                                                    text={tx.description}
                                                />
                                            </TableCell>
                                            <TableCell className="text-right font-mono">
                                                <AmountDisplay
                                                    amountInCents={tx.amount}
                                                    currencyCode={
                                                        tx.currency_code
                                                    }
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
            )}

            <div className="flex justify-between">
                <Button
                    variant="outline"
                    onClick={onBack}
                    disabled={isImporting}
                >
                    {__('Back')}
                </Button>
                <Button
                    onClick={onConfirm}
                    disabled={isImporting || stats.selectedCount === 0}
                >
                    {isImporting
                        ? 'Importing...'
                        : `Import ${stats.selectedCount} Transaction${stats.selectedCount !== 1 ? 's' : ''}`}
                </Button>
            </div>
        </div>
    );
}
