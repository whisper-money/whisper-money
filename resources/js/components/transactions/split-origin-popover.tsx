import { SplitPartsList } from '@/components/transactions/split-parts-list';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useLocale } from '@/hooks/use-locale';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { Merge, Split } from 'lucide-react';

interface SplitOriginPopoverProps {
    transaction: DecryptedTransaction;
    categories: Category[];
    onUnsplit: (transaction: DecryptedTransaction) => void;
}

/**
 * The mark on a row that is one part of a split, and the only place the whole
 * split is visible: the original itself is soft-deleted server-side, but the
 * parts add up to it, so the total comes from them.
 */
export function SplitOriginPopover({
    transaction,
    categories,
    onUnsplit,
}: SplitOriginPopoverProps) {
    const locale = useLocale();
    const siblings = transaction.split_siblings ?? [];
    const others = siblings.filter((sibling) => sibling.id !== transaction.id);
    const originalAmount = siblings.reduce(
        (total, sibling) => total + sibling.amount,
        0,
    );

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    className="size-5 shrink-0 text-muted-foreground"
                    aria-label={__('This transaction is part of a split')}
                >
                    <Split className="size-3.5" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-80">
                <p className="text-xs text-muted-foreground">
                    {__('One of :count splits of :amount', {
                        count: siblings.length,
                        amount: formatCurrency(
                            originalAmount,
                            transaction.currency_code,
                            locale,
                        ),
                    })}
                </p>

                {others.length > 0 && (
                    <div className="mt-3">
                        <SplitPartsList
                            parts={others}
                            categories={categories}
                            currencyCode={transaction.currency_code}
                        />
                    </div>
                )}

                <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => onUnsplit(transaction)}
                    className="mt-3 -ml-2 text-muted-foreground"
                >
                    <Merge />
                    {__('Merge the split back')}
                </Button>
            </PopoverContent>
        </Popover>
    );
}
