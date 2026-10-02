import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { MoveDirection } from '@/lib/rule-builder-utils';
import { ArrowDown, ArrowUp } from 'lucide-react';
import type { ReactNode } from 'react';

interface ReorderActionsProps {
    /** Ties the buttons to their item so focus can move between them after a move. */
    itemId: string;
    canMoveUp: boolean;
    canMoveDown: boolean;
    onMove: (direction: MoveDirection) => void;
    labels: { up: string; down: string; remove: string };
    /** Hover hints for the move buttons, for pointer users. */
    tooltips?: { up: string; down: string };
    removeIcon: ReactNode;
    canRemove?: boolean;
    onRemove: () => void;
}

/**
 * ↑ ↓ | delete, the actions a group carries in its header and a condition in its
 * title row on phones. Ghost icon buttons at 32 px, 40 px on phones for the finger.
 */
export function ReorderActions({
    itemId,
    canMoveUp,
    canMoveDown,
    onMove,
    labels,
    tooltips,
    removeIcon,
    canRemove = true,
    onRemove,
}: ReorderActionsProps) {
    return (
        <div className="flex items-center gap-0.5 max-sm:-mr-2">
            <MoveButton
                itemId={itemId}
                direction="up"
                label={labels.up}
                tooltip={tooltips?.up}
                disabled={!canMoveUp}
                onMove={onMove}
            />
            <MoveButton
                itemId={itemId}
                direction="down"
                label={labels.down}
                tooltip={tooltips?.down}
                disabled={!canMoveDown}
                onMove={onMove}
            />
            <span aria-hidden="true" className="mx-1 h-4 w-px bg-border" />
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                className="max-sm:size-10"
                aria-label={labels.remove}
                disabled={!canRemove}
                onClick={onRemove}
            >
                {removeIcon}
            </Button>
        </div>
    );
}

function MoveButton({
    itemId,
    direction,
    label,
    tooltip,
    disabled,
    onMove,
}: {
    itemId: string;
    direction: MoveDirection;
    label: string;
    tooltip?: string;
    disabled: boolean;
    onMove: (direction: MoveDirection) => void;
}) {
    const button = (
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className="max-sm:size-10"
            aria-label={label}
            disabled={disabled}
            data-reorder-control={`${itemId}:${direction}`}
            onClick={() => onMove(direction)}
        >
            {direction === 'up' ? <ArrowUp /> : <ArrowDown />}
        </Button>
    );

    if (!tooltip) {
        return button;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{button}</TooltipTrigger>
            <TooltipContent>{tooltip}</TooltipContent>
        </Tooltip>
    );
}
