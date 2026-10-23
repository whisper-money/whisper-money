import {
    CASHFLOW_BLOCK_LABELS,
    CASHFLOW_BLOCK_TONES,
} from '@/lib/cashflow-block';
import { cn } from '@/lib/utils';
import { type CashflowBlock } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { X } from 'lucide-react';

interface CashflowBlockChipProps {
    block: CashflowBlock;
    onRemove: () => void;
}

/**
 * The cashflow block the list is narrowed to. It only arrives from a link on
 * the cashflow screen, so it has no place in the filter menu, just this chip
 * to see and drop it.
 */
export function CashflowBlockChip({ block, onRemove }: CashflowBlockChipProps) {
    const blockName = __(CASHFLOW_BLOCK_LABELS[block]);

    return (
        <span
            className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted py-0.5 pr-0.5 pl-2.5 text-xs font-medium text-foreground"
            data-testid="cashflow-block-chip"
        >
            <span
                aria-hidden="true"
                className={cn(
                    'size-2.5 shrink-0 rounded-full',
                    CASHFLOW_BLOCK_TONES[block],
                )}
            />
            {__('Block: :name', { name: blockName })}
            <button
                type="button"
                onClick={onRemove}
                aria-label={__('Remove the :name filter', { name: blockName })}
                className="inline-flex size-5 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}
