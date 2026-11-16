import { CategoryIcon } from '@/components/shared/category-combobox';
import { AmountDisplay } from '@/components/ui/amount-display';
import { type Category } from '@/types/category';
import { type TransactionSplit } from '@/types/transaction';
import { __ } from '@/utils/i18n';

/*
 * A split transaction's lines as the transactions table reads them. Kept
 * apart from the editable category cell so a list that is only read (a
 * widget's, in the chat client's preview) draws them without the app.
 */

export interface SplitBreakdownLine {
    category: Category | null;
    amount: number;
    position: number;
    isRest: boolean;
}

export function buildSplitBreakdown(
    splits: readonly TransactionSplit[],
    categoryFilterIds?: ReadonlySet<string>,
): SplitBreakdownLine[] {
    const ordered = [...splits].sort((a, b) => a.position - b.position);

    if (categoryFilterIds === undefined) {
        return ordered.map((split) => ({
            category: split.category,
            amount: split.amount,
            position: split.position,
            isRest: false,
        }));
    }

    const matching = ordered
        .filter((split) => categoryFilterIds.has(split.category_id))
        .map((split) => ({
            category: split.category,
            amount: split.amount,
            position: split.position,
            isRest: false,
        }));
    const restAmount = ordered
        .filter((split) => !categoryFilterIds.has(split.category_id))
        .reduce((sum, split) => sum + split.amount, 0);

    if (restAmount !== 0) {
        matching.push({
            category: null,
            amount: restAmount,
            position: ordered.length,
            isRest: true,
        });
    }

    return matching;
}

/**
 * "Split · N" and the lines under it, each with its category and amount: what
 * the Category column shows for a split transaction, whether its button opens
 * the edit dialog or it is only read.
 */
export function SplitBreakdown({
    lines,
    count,
    currencyCode,
}: {
    lines: SplitBreakdownLine[];
    /** How many lines the transaction has, filtered out ones included. */
    count: number;
    currencyCode: string;
}) {
    return (
        <span className="block space-y-1 text-sm">
            <span className="font-medium">
                {__('Split')} · {count}
            </span>
            <span className="block space-y-0.5">
                {lines.map((line) => {
                    const name = line.isRest
                        ? __('Rest')
                        : (line.category?.name ?? __('Unknown'));

                    return (
                        <span
                            key={`${line.isRest ? 'rest' : (line.category?.id ?? 'unknown')}-${line.position}`}
                            className="flex min-w-0 items-center gap-1.5"
                            title={name}
                        >
                            {line.category && !line.isRest ? (
                                <span className="shrink-0">
                                    <CategoryIcon category={line.category} />
                                </span>
                            ) : null}
                            <span className="min-w-0 flex-1 truncate">
                                {name}
                            </span>
                            <AmountDisplay
                                amountInCents={line.amount}
                                currencyCode={currencyCode}
                                showSign
                                size="xs"
                                className="shrink-0"
                            />
                        </span>
                    );
                })}
            </span>
        </span>
    );
}
