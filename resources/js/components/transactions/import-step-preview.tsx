import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { type ParsedTransaction } from '@/types/import';
import { useMemo } from 'react';

interface ImportStepPreviewProps {
    transactions: ParsedTransaction[];
    currencyCode: string;
    onConfirm: () => void;
    onBack: () => void;
    onSelectionChange: (index: number, selected: boolean) => void;
    onSelectAll: (selected: boolean) => void;
    isImporting: boolean;
}

export function ImportStepPreview({
    transactions,
    currencyCode,
    onConfirm,
    onBack,
    onSelectionChange,
    onSelectAll,
    isImporting,
}: ImportStepPreviewProps) {
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

    const formatDate = (dateStr: string): string => {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    const handleHeaderCheckboxChange = (checked: boolean) => {
        onSelectAll(checked);
    };

    return (
        <div className="flex flex-col gap-6">
            <div className="flex gap-4 rounded-lg border bg-muted/50 p-4">
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">Total</p>
                    <p className="text-2xl font-bold">{stats.total}</p>
                </div>
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">Selected</p>
                    <p className="text-2xl font-bold text-green-600 dark:text-green-400">
                        {stats.selectedCount}
                    </p>
                </div>
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">Duplicates</p>
                    <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {stats.duplicateCount}
                    </p>
                </div>
            </div>

            {stats.selectableCount === 0 && stats.duplicateCount > 0 && (
                <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-4">
                    <p className="text-sm text-amber-700 dark:text-amber-300">
                        All transactions appear to be duplicates. No new
                        transactions will be imported.
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
                                    aria-label="Select all transactions"
                                />
                            </TableHead>
                            <TableHead className="text-center">
                                Status
                            </TableHead>
                            <TableHead>Date</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {transactions.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={5}
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
                                                Duplicate
                                            </Badge>
                                        ) : (
                                            <Badge
                                                variant="secondary"
                                                className="bg-green-50 text-green-600 dark:bg-green-900 dark:text-green-600"
                                            >
                                                New
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {formatDate(
                                            transaction.transaction_date,
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
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            <div className="flex justify-between">
                <Button
                    variant="outline"
                    onClick={onBack}
                    disabled={isImporting}
                >
                    Back
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
