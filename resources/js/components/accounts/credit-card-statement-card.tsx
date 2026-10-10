import { EditCreditCardDetailDialog } from '@/components/accounts/edit-credit-card-detail-dialog';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLocale } from '@/hooks/use-locale';
import type { SharedData } from '@/types';
import type {
    CreditCardDetail,
    CreditCardStatement,
    CurrencyCode,
} from '@/types/account';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { CalendarClock, Pencil } from 'lucide-react';
import { useState } from 'react';

interface CreditCardStatementCardProps {
    accountId: string;
    currencyCode: CurrencyCode;
    detail: CreditCardDetail | null;
    statement: CreditCardStatement | null;
}

/**
 * The card's next payment, estimated by the server from the statement dates
 * and the card's own transactions. It is a figure to plan with, never a
 * transaction: nothing here is written to the ledger.
 */
export function CreditCardStatementCard({
    accountId,
    currencyCode,
    detail,
    statement,
}: CreditCardStatementCardProps) {
    const { features } = usePage<SharedData>().props;
    const [dialogOpen, setDialogOpen] = useState(false);

    if (!features.creditCardStatements) {
        return null;
    }

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between gap-2">
                <CardTitle className="flex items-center gap-2">
                    {__('Next card payment')}
                    {statement && (
                        <Badge variant="secondary">{__('Estimate')}</Badge>
                    )}
                </CardTitle>
                {detail && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => setDialogOpen(true)}
                    >
                        <Pencil className="h-3.5 w-3.5" />
                        {__('Edit dates')}
                    </Button>
                )}
            </CardHeader>
            <CardContent>
                {statement ? (
                    <StatementSummary
                        statement={statement}
                        currencyCode={currencyCode}
                    />
                ) : (
                    <div className="flex flex-col items-start gap-3">
                        <p className="text-sm text-muted-foreground">
                            {__(
                                'Add the statement closing date and the payment due date of this card to see an estimate of how much will be charged and when.',
                            )}
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setDialogOpen(true)}
                        >
                            <CalendarClock className="h-4 w-4" />
                            {__('Set statement dates')}
                        </Button>
                    </div>
                )}
            </CardContent>

            <EditCreditCardDetailDialog
                accountId={accountId}
                detail={detail}
                open={dialogOpen}
                onOpenChange={setDialogOpen}
            />
        </Card>
    );
}

function StatementSummary({
    statement,
    currencyCode,
}: {
    statement: CreditCardStatement;
    currencyCode: CurrencyCode;
}) {
    const locale = useLocale();
    const { next_payment: nextPayment, current_cycle: currentCycle } =
        statement;
    const formatDay = (date: string) => formatDateMedium(date, locale);

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-1">
                {nextPayment.amount > 0 ? (
                    <AmountDisplay
                        amountInCents={nextPayment.amount}
                        currencyCode={currencyCode}
                        size="2xl"
                        weight="semibold"
                        monospace
                    />
                ) : (
                    <span className="text-2xl font-semibold">
                        {__('Nothing to pay')}
                    </span>
                )}
                <span className="text-sm">
                    {__('Charged on :date', {
                        date: formatDay(nextPayment.due_date),
                    })}
                </span>
                <span className="text-xs text-muted-foreground">
                    {nextPayment.is_final
                        ? __(
                              'Statement closed on :date. It only changes if its transactions are edited.',
                              { date: formatDay(nextPayment.closing_date) },
                          )
                        : __(
                              'The cycle is still open until :date, so this amount can still grow.',
                              { date: formatDay(nextPayment.closing_date) },
                          )}
                </span>
            </div>

            <div className="flex flex-col gap-1 rounded-md border border-border bg-muted/40 p-3 text-sm dark:bg-muted/20">
                <span className="font-medium">{__('Current cycle')}</span>
                <span className="flex flex-wrap items-baseline gap-1">
                    <AmountDisplay
                        amountInCents={Math.max(currentCycle.amount, 0)}
                        currencyCode={currencyCode}
                        weight="medium"
                        monospace
                    />
                    <span className="text-muted-foreground">
                        {__(
                            'so far, closes on :closing and is charged on :due',
                            {
                                closing: formatDay(currentCycle.closing_date),
                                due: formatDay(currentCycle.due_date),
                            },
                        )}
                    </span>
                </span>
            </div>

            <p className="text-xs text-muted-foreground">
                {__(
                    'Estimated from the transactions recorded on this card, leaving out repayments. Your bank statement is the final word.',
                )}
            </p>
        </div>
    );
}
