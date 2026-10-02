import { useWebHaptics } from '@/hooks/use-web-haptics';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import {
    type Announcements,
    DndContext,
    type DragEndEvent,
    KeyboardSensor,
    type Modifier,
    PointerSensor,
    type ScreenReaderInstructions,
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
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { GripVertical } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

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
    /**
     * `list` sorts a single column of items that may differ in height, and only
     * lets the dragged item slide up and down.
     */
    layout?: 'grid' | 'list';
    /** Merged into the drag handle, which carries `data-dragging` while it is held. */
    handleClassName?: string;
    /** Applied to the item while it is being dragged. */
    draggingClassName?: string;
    /** Replaces dnd-kit's English screen reader texts. */
    accessibility?: {
        announcements: Announcements;
        screenReaderInstructions: ScreenReaderInstructions;
    };
}

const restrictToVerticalAxis: Modifier = ({ transform }) => ({
    ...transform,
    x: 0,
});

export function SortableGrid<T>({
    items,
    getId,
    renderItem,
    onReorder,
    className,
    footer,
    layout = 'grid',
    handleClassName,
    draggingClassName = 'opacity-60',
    accessibility,
}: SortableGridProps<T>) {
    const ids = items.map(getId);
    const { trigger } = useWebHaptics();
    const [isDragging, setIsDragging] = useState(false);

    // A small move starts the drag, so taps/clicks still work. Touch is handled
    // via pointer events and only the handle has touch-action: none, so the rest
    // of the card scrolls normally on mobile (no long-press, which blocked it).
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    // Escape cancels a drag, but a Radix dialog around the grid listens for it in
    // the capture phase and would close first. Marking the key as handled before
    // it reaches the dialog keeps the dialog open; dnd-kit still cancels on it.
    useEffect(() => {
        if (!isDragging) {
            return;
        }

        const keepDialogOpen = (event: KeyboardEvent): void => {
            if (event.key === 'Escape') {
                event.preventDefault();
            }
        };

        window.addEventListener('keydown', keepDialogOpen, { capture: true });

        return () =>
            window.removeEventListener('keydown', keepDialogOpen, {
                capture: true,
            });
    }, [isDragging]);

    function handleDragEnd(event: DragEndEvent): void {
        setIsDragging(false);

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
            modifiers={layout === 'list' ? [restrictToVerticalAxis] : undefined}
            accessibility={accessibility}
            onDragStart={() => setIsDragging(true)}
            onDragCancel={() => setIsDragging(false)}
            onDragEnd={handleDragEnd}
        >
            <SortableContext
                items={ids}
                strategy={
                    layout === 'list'
                        ? verticalListSortingStrategy
                        : rectSortingStrategy
                }
            >
                <div className={className}>
                    {items.map((item) => (
                        <SortableItem
                            key={getId(item)}
                            id={getId(item)}
                            onActivate={() => trigger('selection')}
                            // A list only slides items, so a dragged item that is
                            // styled larger than its slot is not squashed to fit it.
                            slideOnly={layout === 'list'}
                            handleClassName={handleClassName}
                            draggingClassName={draggingClassName}
                        >
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
    onActivate,
    slideOnly,
    handleClassName,
    draggingClassName,
    children,
}: {
    id: string;
    onActivate: () => void;
    slideOnly: boolean;
    handleClassName?: string;
    draggingClassName: string;
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

    const dragHandle = (
        <button
            ref={setActivatorNodeRef}
            type="button"
            aria-label={__('Drag to reorder')}
            data-dragging={isDragging || undefined}
            className={cn(
                'cursor-grab touch-none text-muted-foreground transition-colors select-none hover:text-foreground active:cursor-grabbing',
                handleClassName,
            )}
            {...attributes}
            {...listeners}
            onPointerDown={(event) => {
                onActivate();
                listeners?.onPointerDown?.(event);
            }}
            // Holding the handle otherwise fires Android Chrome's long-press
            // haptic, a second buzz on top of the one we fire on pointer down.
            onContextMenu={(event) => event.preventDefault()}
        >
            <GripVertical className="size-5" />
        </button>
    );

    return (
        <div
            ref={setNodeRef}
            style={{
                transform: slideOnly
                    ? CSS.Translate.toString(transform)
                    : CSS.Transform.toString(transform),
                transition,
                zIndex: isDragging ? 50 : undefined,
            }}
            className={cn('group relative', isDragging && draggingClassName)}
        >
            {children(dragHandle)}
        </div>
    );
}
