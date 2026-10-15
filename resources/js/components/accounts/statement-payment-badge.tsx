import { Badge } from '@/components/ui/badge';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import type { CreditCardPayment } from '@/types/account';
import { getLabelColorClasses } from '@/types/label';
import { formatDayFromDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Receipt } from 'lucide-react';

/** The text of a payment's badge: the statement it pays, in short dates. */
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
 * Marks a credit card repayment in the transaction list with the statement
 * it pays, in the same shape as a label badge.
 */
export function StatementPaymentBadge({
    payment,
}: {
    payment: CreditCardPayment;
}) {
    const locale = useLocale();
    const colors = getLabelColorClasses('emerald');

    return (
        <Badge
            className={cn('shrink-0 gap-1 px-2 py-0.5', colors.bg, colors.text)}
            data-testid="statement-payment-badge"
        >
            <Receipt className="h-3 w-3" />
            {statementPaymentText(payment, locale)}
        </Badge>
    );
}
