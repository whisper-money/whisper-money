import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { DashboardPicker } from './dashboard-picker';

vi.mock('./dashboard-actions', () => ({
    dashboardActions: { open: vi.fn() },
    SYSTEM_DASHBOARD_QUERY: 'system',
}));

function renderPicker(
    overrides: Partial<Parameters<typeof DashboardPicker>[0]> = {},
) {
    const handlers = {
        onCreate: vi.fn(),
        onCreateWithAi: vi.fn(),
        onManage: vi.fn(),
    };

    render(
        <DashboardPicker
            dashboards={[]}
            selectedId={null}
            selectedName="Overview"
            defaultId={null}
            canCreate={false}
            canManage
            isLoading={false}
            {...handlers}
            {...overrides}
        />,
    );

    return handlers;
}

async function openPicker(): Promise<string[]> {
    fireEvent.pointerDown(
        screen.getByRole('button', { name: 'Switch dashboard' }),
        { button: 0, ctrlKey: false },
    );

    return (await screen.findAllByRole('menuitem')).map(
        (item) => item.textContent ?? '',
    );
}

describe('DashboardPicker', () => {
    it('offers the AI guide right under New dashboard, without the plan badge', async () => {
        const { onCreateWithAi } = renderPicker();

        const items = await openPicker();
        const newIndex = items.findIndex((text) =>
            text.startsWith('New dashboard'),
        );

        expect(items[newIndex + 1]).toBe(
            'Create a dashboard with AIAsk your assistant',
        );

        fireEvent.click(
            screen.getByRole('menuitem', {
                name: /Create a dashboard with AI/,
            }),
        );

        expect(onCreateWithAi).toHaveBeenCalledOnce();
    });

    it('hides the AI guide where New dashboard is hidden', async () => {
        renderPicker({ canManage: false });

        const items = await openPicker();

        expect(
            items.some((text) => text.includes('Create a dashboard with AI')),
        ).toBe(false);
    });
});
