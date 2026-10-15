import { useLocale } from '@/hooks/use-locale';
import type { CreditCardPayment } from '@/types/account';
import { formatDayFromDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Receipt } from 'lucide-react';

/** The statement a payment pays, in short dates. */
export function statementPaymentText(
    payment: CreditCardPayment,
    locale: string,
): string {
    return __('Statement payment :from – :to', {
        from: formatDayFromDate(payment.period_from, locale),
        to: formatDayFromDate(payment.closing_date, locale),
    });
}

/**
 * A quiet line under a credit card repayment's description, saying which
 * statement it pays. Smaller than the notes line, so it never competes with it.
 */
export function StatementPaymentNote({
    payment,
}: {
    payment: CreditCardPayment;
}) {
    const locale = useLocale();

    return (
        <div
            className="flex items-center gap-1 text-xs text-muted-foreground"
            data-testid="statement-payment-note"
        >
            <Receipt className="h-3 w-3 shrink-0" aria-hidden />
            <span className="truncate">
                {statementPaymentText(payment, locale)}
            </span>
        </div>
    );
}
