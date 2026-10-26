import {
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuSeparator,
} from '@/components/ui/context-menu';
import {
    getTransactionRowActions,
    type TransactionRowActionHandlers,
} from '@/lib/transaction-row-actions';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Fragment } from 'react';

/**
 * The right-click menu of a transaction, the same on a table row of either
 * page and on a card.
 */
export function TransactionRowContextMenuContent({
    transaction,
    ...handlers
}: TransactionRowActionHandlers & { transaction: ServerTransaction }) {
    return (
        <ContextMenuContent>
            <ContextMenuLabel>{__('Actions')}</ContextMenuLabel>
            {getTransactionRowActions({ transaction, ...handlers }).map(
                (action) => (
                    <Fragment key={action.id}>
                        {action.separated && <ContextMenuSeparator />}
                        <ContextMenuItem
                            onClick={action.onSelect}
                            variant={action.variant}
                        >
                            {action.label}
                        </ContextMenuItem>
                    </Fragment>
                ),
            )}
        </ContextMenuContent>
    );
}
