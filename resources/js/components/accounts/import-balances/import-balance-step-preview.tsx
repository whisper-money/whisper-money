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
    const total = balances.length;

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
            <div className="rounded-lg border bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">
                    {total} balance{total !== 1 ? 's' : ''} will be updated or
                    created.
                </p>
            </div>

            <div className="max-h-[400px] overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Date</TableHead>
                            <TableHead className="text-right">
                                Balance
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {balances.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={2}
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
                    disabled={isImporting || total === 0}
                >
                    {isImporting
                        ? 'Importing...'
                        : `Import ${total} Balance${total !== 1 ? 's' : ''}`}
                </Button>
            </div>
        </div>
    );
}
