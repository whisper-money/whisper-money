import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { type ParsedBalance } from '@/types/balance-import';
import { useMemo } from 'react';

interface ImportBalanceStepPreviewProps {
    balances: ParsedBalance[];
    currencyCode: string;
    onConfirm: () => void;
    onBack: () => void;
    isImporting: boolean;
}

export function ImportBalanceStepPreview({
    balances,
    currencyCode,
    onConfirm,
    onBack,
    isImporting,
}: ImportBalanceStepPreviewProps) {
    const stats = useMemo(() => {
        const newCount = balances.filter((b) => !b.isExisting).length;
        const existingCount = balances.filter((b) => b.isExisting).length;
        return { newCount, existingCount, total: balances.length };
    }, [balances]);

    const formatBalance = (balance: number): string => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currencyCode,
        }).format(balance / 100);
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
                    <p className="text-sm text-muted-foreground">Will Update</p>
                    <p className="text-2xl font-bold text-amber-600 dark:text-amber-400">
                        {stats.existingCount}
                    </p>
                </div>
            </div>

            {stats.existingCount > 0 && (
                <div className="rounded-lg border border-amber-500/20 bg-amber-500/10 p-4">
                    <p className="text-sm text-amber-700 dark:text-amber-300">
                        {stats.existingCount} balance
                        {stats.existingCount !== 1 ? 's' : ''} already exist for
                        the same date{stats.existingCount !== 1 ? 's' : ''} and
                        will be updated with the new values.
                    </p>
                </div>
            )}

            <div className="max-h-[400px] overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead className="text-right">
                                Balance
                            </TableHead>
                            <TableHead className="text-center">
                                Status
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {balances.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={3}
                                    className="text-center text-muted-foreground"
                                >
                                    No valid balances found
                                </TableCell>
                            </TableRow>
                        ) : (
                            balances.map((balance, index) => (
                                <TableRow key={index}>
                                    <TableCell className="whitespace-nowrap">
                                        {formatDate(balance.balance_date)}
                                    </TableCell>
                                    <TableCell className="text-right font-mono">
                                        {formatBalance(balance.balance)}
                                    </TableCell>
                                    <TableCell className="text-center">
                                        {balance.isExisting ? (
                                            <Badge variant="secondary">
                                                Will Update
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
                    disabled={isImporting || stats.total === 0}
                >
                    {isImporting
                        ? 'Importing...'
                        : `Import ${stats.total} Balance${stats.total !== 1 ? 's' : ''}`}
                </Button>
            </div>
        </div>
    );
}
