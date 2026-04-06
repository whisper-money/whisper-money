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
import { balanceTermCapitalized } from '@/types/account';
import { type ParsedBalance } from '@/types/balance-import';
import { formatCurrency } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';

interface ImportBalanceStepPreviewProps {
    balances: ParsedBalance[];
    currencyCode: string;
    investedAmountCurrencyCode: string;
    showInvestedAmount: boolean;
    isLoan?: boolean;
    onConfirm: () => void;
    onBack: () => void;
    isImporting: boolean;
}

export function ImportBalanceStepPreview({
    balances,
    currencyCode,
    investedAmountCurrencyCode,
    showInvestedAmount,
    isLoan = false,
    onConfirm,
    onBack,
    isImporting,
}: ImportBalanceStepPreviewProps) {
    const locale = useLocale();
    const total = balances.length;

    const formatBalance = (valueInCents: number): string =>
        formatCurrency(valueInCents, currencyCode, locale);

    const formatInvestedAmount = (valueInCents: number): string =>
        formatCurrency(valueInCents, investedAmountCurrencyCode, locale);

    const hasInvestedData =
        showInvestedAmount && balances.some((b) => b.invested_amount !== null);
    const colSpan = hasInvestedData ? 3 : 2;

    return (
        <div className="flex flex-col gap-6">
            <div className="rounded-lg border bg-muted/50 p-4">
                <p className="text-sm text-muted-foreground">
                    {total}{' '}
                    {isLoan
                        ? total !== 1
                            ? __('owed amounts')
                            : __('owed amount')
                        : total !== 1
                          ? __('balances')
                          : __('balance')}{' '}
                    {__('will be updated or created.')}
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
                                {balanceTermCapitalized(
                                    isLoan ? 'loan' : 'checking',
                                )}
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
                                    {isLoan
                                        ? __('No valid owed amounts found')
                                        : __('No valid balances found')}
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
                                                ? formatInvestedAmount(
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
                        : isLoan
                          ? `Import ${total} Owed Amount${total !== 1 ? 's' : ''}`
                          : `Import ${total} Balance${total !== 1 ? 's' : ''}`}
                </Button>
            </div>
        </div>
    );
}
