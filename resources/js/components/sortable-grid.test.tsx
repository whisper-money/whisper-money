import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { SortableGrid } from './sortable-grid';

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
