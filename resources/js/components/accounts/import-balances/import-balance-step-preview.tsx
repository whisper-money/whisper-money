import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useLocale } from '@/hooks/use-locale';
import { type ParsedBalance } from '@/types/balance-import';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';

interface ImportBalanceStepPreviewProps {
    balances: ParsedBalance[];
    currencyCode: string;
    showInvestedAmount: boolean;
    onConfirm: () => void;
    onBack: () => void;
    isImporting: boolean;
}

export function ImportBalanceStepPreview({
    balances,
    currencyCode,
    showInvestedAmount,
    onConfirm,
    onBack,
    isImporting,
}: ImportBalanceStepPreviewProps) {
    const locale = useLocale();
    const total = balances.length;

    const formatBalance = (balance: number): string => {
        return new Intl.NumberFormat(locale, {
            style: 'currency',
            currency: currencyCode,
        }).format(balance / 100);
    };

    const hasInvestedData =
        showInvestedAmount && balances.some((b) => b.invested_amount !== null);
    const colSpan = hasInvestedData ? 3 : 2;

    return (
        <div className="flex flex-col gap-6">
            <div className="rounded-lg border bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">
                    {total} balance{total !== 1 ? 's' : ''}
                    {__('will be updated or\n                    created.')}
                </p>
            </div>

            <div className="max-h-[400px] overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>{__('Date')}</TableHead>
                            {hasInvestedData && (
                                <TableHead className="text-right">
                                    {__('Invested')}
                                </TableHead>
                            )}
                            <TableHead className="text-right">
                                {__('Balance')}
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {balances.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={colSpan}
                                    className="text-center text-muted-foreground"
                                >
                                    No valid balances found
                                </TableCell>
                            </TableRow>
                        ) : (
                            balances.map((balance, index) => (
                                <TableRow key={index}>
                                    <TableCell className="whitespace-nowrap">
                                        {formatDateMedium(
                                            balance.balance_date,
                                            locale,
                                        )}
                                    </TableCell>
                                    {hasInvestedData && (
                                        <TableCell className="text-right font-mono text-muted-foreground">
                                            {balance.invested_amount !== null
                                                ? formatBalance(
                                                      balance.invested_amount,
                                                  )
                                                : '—'}
                                        </TableCell>
                                    )}
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
                    {__('Back')}
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
