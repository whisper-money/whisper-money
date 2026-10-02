import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SortableGrid } from './sortable-grid';
import { Dialog, DialogContent, DialogTitle } from './ui/dialog';

const triggerMock = vi.fn();

vi.mock('@/hooks/use-web-haptics', () => ({
    useWebHaptics: () => ({
        trigger: triggerMock,
        cancel: vi.fn(),
        isSupported: true,
    }),
}));

function renderGrid() {
    return render(
        <SortableGrid
            items={[{ id: 'a' }, { id: 'b' }]}
            getId={(item) => item.id}
            onReorder={() => {}}
            renderItem={(item, handle) => (
                <div>
                    {handle}
                    <span>{item.id}</span>
                </div>
            )}
        />,
    );
}

describe('SortableGrid drag handle', () => {
    it('fires the selection haptic once on pointer down', () => {
        triggerMock.mockReset();
        renderGrid();

        fireEvent.pointerDown(screen.getAllByLabelText('Drag to reorder')[0], {
            pointerId: 1,
        });

        expect(triggerMock).toHaveBeenCalledOnce();
        expect(triggerMock).toHaveBeenCalledWith('selection');
    });

    it('suppresses the native long-press context menu (Android long-press haptic)', () => {
        renderGrid();

        const prevented = fireEvent.contextMenu(
            screen.getAllByLabelText('Drag to reorder')[0],
        );

        expect(prevented).toBe(false);
    });
});

describe('SortableGrid inside a dialog', () => {
    function renderInDialog() {
        const onOpenChange = vi.fn();
        render(
            <Dialog open onOpenChange={onOpenChange}>
                <DialogContent>
                    <DialogTitle>Reorder</DialogTitle>
                    <SortableGrid
                        layout="list"
                        items={[{ id: 'a' }, { id: 'b' }]}
                        getId={(item) => item.id}
                        onReorder={() => {}}
                        renderItem={(item, handle) => (
                            <div>
                                {handle}
                                <span>{item.id}</span>
                            </div>
                        )}
                    />
                </DialogContent>
            </Dialog>,
        );

        return onOpenChange;
    }

    function pressEscape(target: Element): void {
        fireEvent.keyDown(target, { key: 'Escape', code: 'Escape' });
    }

    it('still closes on Escape when nothing is being dragged', () => {
        const onOpenChange = renderInDialog();

        pressEscape(screen.getAllByLabelText('Drag to reorder')[0]);

        expect(onOpenChange).toHaveBeenCalledWith(false);
    });

    // Radix listens for Escape before dnd-kit does, so without the guard the key
    // meant to cancel a keyboard drag closed the whole rule dialog instead.
    it('stays open when Escape cancels a keyboard drag', async () => {
        const onOpenChange = renderInDialog();
        const handle = screen.getAllByLabelText('Drag to reorder')[0];

        fireEvent.keyDown(handle, { key: ' ', code: 'Space' });
        // dnd-kit starts listening for the keys that move or cancel on the next tick.
        await act(() => new Promise((resolve) => setTimeout(resolve, 0)));
        pressEscape(handle);

        expect(onOpenChange).not.toHaveBeenCalled();
    });
});
