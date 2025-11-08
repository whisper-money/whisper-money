import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
    isImporting: boolean;
}

export function ImportStepPreview({
    transactions,
    currencyCode,
    onConfirm,
    onBack,
    isImporting,
}: ImportStepPreviewProps) {
    const stats = useMemo(() => {
        const newCount = transactions.filter((t) => !t.isDuplicate).length;
        const duplicateCount = transactions.filter((t) => t.isDuplicate).length;
        return { newCount, duplicateCount, total: transactions.length };
    }, [transactions]);

    const formatAmount = (amount: number): string => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currencyCode,
        }).format(amount);
    };

    const formatDate = (dateStr: string): string => {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    return (
        <div className="flex flex-col gap-6">
            <div className="flex gap-4 rounded-lg border bg-muted/50 p-4">
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">Total</p>
                    <p className="text-2xl font-bold">{stats.total}</p>
                </div>
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">New</p>
                    <p className="text-2xl font-bold text-green-600 dark:text-green-400">
                        {stats.newCount}
                    </p>
                </div>
                <div className="flex-1">
                    <p className="text-sm text-muted-foreground">Duplicates</p>
                    <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {stats.duplicateCount}
                    </p>
                </div>
            </div>

            {stats.newCount === 0 && stats.duplicateCount > 0 && (
                <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-4">
                    <p className="text-sm text-amber-700 dark:text-amber-300">
                        All transactions appear to be duplicates. No new transactions will be imported.
                    </p>
                </div>
            )}

            <div className="max-h-[400px] overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead className="text-right">Amount</TableHead>
                            <TableHead className="text-center">Status</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {transactions.length === 0 ? (
                            <TableRow>
                                <TableCell colSpan={4} className="text-center text-muted-foreground">
                                    No valid transactions found
                                </TableCell>
                            </TableRow>
                        ) : (
                            transactions.map((transaction, index) => (
                                <TableRow
                                    key={index}
                                    className={
                                        transaction.isDuplicate ? 'opacity-60' : ''
                                    }
                                >
                                    <TableCell className="whitespace-nowrap">
                                        {formatDate(transaction.transaction_date)}
                                    </TableCell>
                                    <TableCell className="max-w-[200px] truncate">
                                        {transaction.description}
                                    </TableCell>
                                    <TableCell className="text-right font-mono">
                                        {formatAmount(transaction.amount)}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {transaction.isDuplicate ? (
                                            <Badge variant="secondary">Duplicate</Badge>
                                        ) : (
                                            <Badge variant="default">New</Badge>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            <div className="flex justify-between">
                <Button variant="outline" onClick={onBack} disabled={isImporting}>
                    Back
                </Button>
                <Button
                    onClick={onConfirm}
                    disabled={isImporting || stats.newCount === 0}
                >
                    {isImporting
                        ? 'Importing...'
                        : `Import ${stats.newCount} Transaction${stats.newCount !== 1 ? 's' : ''}`}
                </Button>
            </div>
        </div>
    );
}

