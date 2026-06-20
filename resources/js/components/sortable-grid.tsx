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
    renderItem: (item: T) => ReactNode;
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
                            {renderItem(item)}
                        </SortableItem>
                    ))}
                    {footer}
                </div>
            </SortableContext>
        </DndContext>
    );
}

function SortableItem({ id, children }: { id: string; children: ReactNode }) {
    const {
        attributes,
        listeners,
        setNodeRef,
        setActivatorNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id });

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: CSS.Transform.toString(transform),
                transition,
                zIndex: isDragging ? 50 : undefined,
            }}
            className={cn('relative', isDragging && 'opacity-60')}
        >
            {children}
            <button
                ref={setActivatorNodeRef}
                type="button"
                aria-label={__('Drag to reorder')}
                className="absolute top-1 left-1/2 -translate-x-1/2 cursor-grab touch-none rounded p-1.5 text-muted-foreground/40 transition-colors hover:text-foreground active:cursor-grabbing"
                {...attributes}
                {...listeners}
            >
                <GripVertical className="size-4" />
            </button>
        </div>
    );
}
