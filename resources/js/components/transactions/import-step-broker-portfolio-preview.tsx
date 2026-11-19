import { FOOTNOTE } from '@/components/transactions/import-section';
import {
    CompactTransactionTable,
    type CompactRow,
} from '@/components/transactions/import-step-preview';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import { type Trade } from '@/lib/broker-imports/types';
import { __ } from '@/utils/i18n';
import { type ReactNode } from 'react';

function operationLabel(order: Trade): string {
    if (order.isTransfer) {
        return __('Transfer · :name', { name: order.name });
    }

    return order.side === 'buy'
        ? __('Buy · :name', { name: order.name })
        : __('Sell · :name', { name: order.name });
}

/** An order as a row: money into the portfolio is positive, out of it negative. */
function operationRow(order: Trade): CompactRow {
    return {
        key: order.externalId,
        date: order.date,
        description: operationLabel(order),
        amountInCents: order.side === 'buy' ? order.amount : -order.amount,
        currencyCode: order.currency,
    };
}

/**
 * The preview of an import that brings no transactions, only a portfolio's
 * daily balances. There is nothing to pick: the operations are listed for
 * reference, newest first, under the strip of where the portfolio will stand.
 */
export function PortfolioOperationsPreview({
    orders,
    summary,
    footnote,
    isImporting,
    onBack,
    onConfirm,
}: {
    orders: Trade[];
    summary: ReactNode;
    /** Why there is nothing to pick, under the list. */
    footnote: string;
    isImporting: boolean;
    onBack: () => void;
    onConfirm: () => void;
}) {
    const locale = useLocale();
    const rows = orders
        .map(operationRow)
        .sort((a, b) => b.date.localeCompare(a.date));

    return (
        <div className="flex flex-col gap-4">
            {summary}

            <div className="overflow-hidden rounded-lg border">
                <div className="max-h-[45vh] overflow-auto">
                    <CompactTransactionTable rows={rows} locale={locale} />
                </div>
                <p className={FOOTNOTE}>{footnote}</p>
            </div>

            <div className="flex items-center justify-between pt-2">
                <Button
                    variant="outline"
                    onClick={onBack}
                    disabled={isImporting}
                >
                    {__('Back')}
                </Button>
                <Button
                    onClick={onConfirm}
                    disabled={isImporting || rows.length === 0}
                >
                    {isImporting
                        ? __('Importing...')
                        : __('Import portfolio history')}
                </Button>
            </div>
        </div>
    );
}
