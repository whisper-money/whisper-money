import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import {
    DndContext,
    type DragEndEvent,
    KeyboardSensor,
    PointerSensor,
    TouchSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    rectSortingStrategy,
    sortableKeyboardCoordinates,
    useSortable,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import type { ReactNode } from 'react';

interface SortableGridProps<T> {
    items: T[];
    getId: (item: T) => string;
    /**
     * Renders one item. The provided drag handle must be placed inside the
     * card so it sits exactly where the card wants it (e.g. over the account
     * icon); it only becomes visible on hover via the wrapper's `group`.
     */
    renderItem: (item: T, dragHandle: ReactNode) => ReactNode;
    onReorder: (orderedIds: string[]) => void;
    className?: string;
    /** Non-sortable content rendered inside the grid after the items. */
    footer?: ReactNode;
}

export function SortableGrid<T>({
    items,
    getId,
    renderItem,
    onReorder,
    className,
    footer,
}: SortableGridProps<T>) {
    const ids = items.map(getId);

    // Pointer drag starts after a small move (so clicks still work); touch drag
    // starts on a long press, leaving quick swipes free to scroll the page.
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(TouchSensor, {
            activationConstraint: { delay: 200, tolerance: 8 },
        }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    function handleDragEnd(event: DragEndEvent): void {
        const { active, over } = event;
        if (!over || active.id === over.id) {
            return;
        }

        const oldIndex = ids.indexOf(String(active.id));
        const newIndex = ids.indexOf(String(over.id));
        if (oldIndex === -1 || newIndex === -1) {
            return;
        }

        onReorder(arrayMove(ids, oldIndex, newIndex));
    }

    return (
        <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
        >
            <SortableContext items={ids} strategy={rectSortingStrategy}>
                <div className={className}>
                    {items.map((item) => (
                        <SortableItem key={getId(item)} id={getId(item)}>
                            {(dragHandle) => renderItem(item, dragHandle)}
                        </SortableItem>
                    ))}
                    {footer}
                </div>
            </SortableContext>
        </DndContext>
    );
}

function SortableItem({
    id,
    children,
}: {
    id: string;
    children: (dragHandle: ReactNode) => ReactNode;
}) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    // Collapsed to zero width by default; on card hover it expands and slides
    // in, pushing the card's leading content (logo + name) to the right.
    const dragHandle = (
        <button
            ref={setActivatorNodeRef}
            type="button"
            aria-label={__('Drag to reorder')}
            className="flex w-0 shrink-0 cursor-grab touch-none items-center overflow-hidden text-muted-foreground/70 opacity-0 transition-all duration-200 ease-out group-hover:w-6 group-hover:opacity-100 hover:text-foreground active:cursor-grabbing"
            {...attributes}
            {...listeners}
        >
            <GripVertical className="size-5" />
        </button>
    );

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
                zIndex: isDragging ? 50 : undefined,
            }}
            className={cn('group relative', isDragging && 'opacity-60')}
        >
            {children(dragHandle)}
        </div>
    );
}
