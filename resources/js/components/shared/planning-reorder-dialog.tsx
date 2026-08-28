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
 * Reordering for touch. The cards themselves only take a drag from `md` up,
 * where a hover reveals the grip; below that the pencil next to the heading
 * opens this compact list instead.
 */
export function PlanningReorderDialog({
    open,
    onOpenChange,
    items,
    onReorder,
}: Props) {
    // With savings goals switched off everything is a budget, so the type hint
    // would only be noise.
    const showType = items.some((item) => item.type === 'goal');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{__('Reorder')}</DialogTitle>
                    <DialogDescription>
                        {__('Drag to reorder your budgets and savings goals.')}
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
                            {showType && (
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
