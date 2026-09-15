import { StepButton } from '@/components/onboarding/step-button';
import { StepList } from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepEmphasis,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { cn } from '@/lib/utils';
import { type ParsedTransaction } from '@/types/import';
import { formatCurrency } from '@/utils/currency';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { useMemo } from 'react';

/** How much of the file is shown before the count takes over. */
const SAMPLE_SIZE = 3;

/**
 * What the file turned out to hold, in the terms the user recognises: how many
 * movements, over what stretch of their life.
 */
function describeSpan(
    rows: ParsedTransaction[],
    locale: string,
): { months: number; from: string; to: string } | null {
    const dates = rows
        .map((row) => row.transaction_date)
        .filter(Boolean)
        .sort();

    if (dates.length === 0) {
        return null;
    }

    const first = new Date(dates[0]);
    const last = new Date(dates[dates.length - 1]);

    return {
        months:
            (last.getFullYear() - first.getFullYear()) * 12 +
            (last.getMonth() - first.getMonth()) +
            1,
        from: formatDate(first, 'MMMM', locale),
        to: formatDate(last, 'MMMM', locale),
    };
}

interface StepImportPreviewProps {
    /** Everything read from the file, duplicates included and already deselected. */
    transactions: ParsedTransaction[];
    currencyCode: string;
    locale: string;
    onConfirm: () => void;
    onDifferentFile: () => void;
}

export function StepImportPreview({
    transactions,
    currencyCode,
    locale,
    onConfirm,
    onDifferentFile,
}: StepImportPreviewProps) {
    const selected = useMemo(
        () => transactions.filter((transaction) => transaction.selected),
        [transactions],
    );
    const duplicateCount = transactions.length - selected.length;
    const span = useMemo(
        () => describeSpan(transactions, locale),
        [transactions, locale],
    );

    const title =
        span && span.months > 1
            ? __(':count movements, :months months', {
                  count: transactions.length,
                  months: span.months,
              })
            : __(':count movements', { count: transactions.length });

    const importLabel =
        selected.length === 1
            ? __('Import 1 movement')
            : __('Import :count movements', { count: selected.length });

    return (
        <StepScreen
            title={title}
            description={
                span
                    ? __(
                          ':from to :to. Nothing is saved until you say the word.',
                          { from: span.from, to: span.to },
                      )
                    : __('Nothing is saved until you say the word.')
            }
            footer={
                <>
                    <StepButton
                        text={importLabel}
                        disabled={selected.length === 0}
                        onClick={onConfirm}
                    />
                    <StepButton
                        text={__('Use a different file')}
                        variant="ghost"
                        onClick={onDifferentFile}
                    />
                </>
            }
        >
            <div className="flex flex-col gap-6">
                <StepList>
                    {transactions.slice(0, SAMPLE_SIZE).map((row, index) => (
                        <div
                            key={`${row.transaction_date}-${index}`}
                            className="flex min-h-11 items-center gap-3.5 py-4"
                        >
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="truncate text-base leading-tight font-medium">
                                    {row.description}
                                </span>
                                <span className="text-sm text-muted-foreground">
                                    {formatDate(
                                        row.transaction_date,
                                        'd MMM',
                                        locale,
                                    )}
                                </span>
                            </span>
                            <span
                                className={cn(
                                    'shrink-0 text-base font-medium tabular-nums',
                                    row.amount > 0 && 'text-emerald-600',
                                )}
                            >
                                {row.amount > 0 && '+'}
                                {formatCurrency(
                                    row.amount,
                                    row.currency_code ?? currencyCode,
                                    locale,
                                )}
                            </span>
                        </div>
                    ))}

                    {transactions.length > SAMPLE_SIZE && (
                        <div className="flex min-h-11 items-center py-4 text-sm text-muted-foreground">
                            {__('and :count more', {
                                count: transactions.length - SAMPLE_SIZE,
                            })}
                        </div>
                    )}
                </StepList>

                {duplicateCount > 0 && (
                    <StepCallout>
                        <StepEmphasis
                            sentence={__(
                                ":duplicates of movements you already have. We'll leave those out — you can bring them back later.",
                            )}
                            word={__(':count look like duplicates', {
                                count: duplicateCount,
                            })}
                        />
                    </StepCallout>
                )}
            </div>
        </StepScreen>
    );
}
