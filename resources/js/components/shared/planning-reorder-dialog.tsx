import { SortableGrid } from '@/components/sortable-grid';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { PlanningItem } from '@/lib/planning-items';
import { __ } from '@/utils/i18n';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The live items currently on screen, in display order. */
    items: PlanningItem[];
    onReorder: (orderedIds: string[]) => void;
}

/**
 * Where the Planning list gets reordered, on every viewport. The cards stay
 * undraggable — one compact list behaves the same under a mouse and a finger,
 * and it does not put a drag target on top of a card that is also a link.
 */
export function PlanningReorderDialog({
    open,
    onOpenChange,
    items,
    onReorder,
}: Props) {
    // With savings goals switched off everything is a budget, so neither the
    // type hint nor a description naming goals would say anything true.
    const hasGoals = items.some((item) => item.type === 'goal');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__('Edit order')}</DialogTitle>
                    <DialogDescription>
                        {hasGoals
                            ? __(
                                  'Drag to reorder your budgets and savings goals.',
                              )
                            : __('Drag to reorder your budgets.')}
                    </DialogDescription>
                </DialogHeader>

                <SortableGrid
                    className="flex flex-col gap-1"
                    items={items}
                    getId={(item) => item.id}
                    onReorder={onReorder}
                    renderItem={(item, dragHandle) => (
                        <div className="flex items-center gap-3 rounded-md px-2 py-2 hover:bg-muted">
                            <span className="flex-1 truncate text-sm">
                                {item.name}
                            </span>
                            {hasGoals && (
                                <span className="shrink-0 text-xs text-muted-foreground">
                                    {item.type === 'budget'
                                        ? __('Budget')
                                        : __('Savings Goal')}
                                </span>
                            )}
                            {dragHandle}
                        </div>
                    )}
                />
            </DialogContent>
        </Dialog>
    );
}
