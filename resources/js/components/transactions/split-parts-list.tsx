import { CategoryBadge } from '@/components/shared/category-combobox';
import { AmountDisplay } from '@/components/ui/amount-display';
import { cn } from '@/lib/utils';
import { type Category } from '@/types/category';
import { type TransactionSplitSibling } from '@/types/transaction';
import { __ } from '@/utils/i18n';

interface SplitPartsListProps {
    parts: TransactionSplitSibling[];
    categories: Category[];
    currencyCode: string;
    /** Extra classes per row — the merge dialog strikes its rows through. */
    rowClassName?: string;
}

/** The parts of a split, each as its category and its share of the amount. */
export function SplitPartsList({
    parts,
    categories,
    currencyCode,
    rowClassName,
}: SplitPartsListProps) {
    return (
        <div className="flex flex-col gap-2">
            {parts.map((part) => {
                const category = categories.find(
                    (candidate) => candidate.id === part.category_id,
                );

                return (
                    <div
                        key={part.id}
                        className={cn(
                            'flex items-center justify-between gap-3 text-sm',
                            rowClassName,
                        )}
                    >
                        {category ? (
                            <CategoryBadge category={category} />
                        ) : (
                            <span className="truncate text-muted-foreground">
                                {__('Uncategorized')}
                            </span>
                        )}
                        <AmountDisplay
                            amountInCents={part.amount}
                            currencyCode={currencyCode}
                            className="shrink-0"
                            monospace
                        />
                    </div>
                );
            })}
        </div>
    );
}
