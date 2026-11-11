import { type UserWidgetDefinition } from '@/types/dashboard';
import { type UserWidgetsGate } from '@/widgets/runtime/use-user-widgets-gate';
import { type UserWidgetProps } from '@/widgets/runtime/user-widget';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { type PlacedUserWidget } from './grid-layout';
import { type UserWidgetDataState } from './use-user-widget-data';
import { UserWidgetCell, type UserWidgetCellProps } from './user-widget-cell';

const drawn = vi.hoisted(() => ({ props: null as UserWidgetProps | null }));

vi.mock('@/widgets/runtime/user-widget', () => ({
    UserWidget: (props: UserWidgetProps) => {
        drawn.props = props;

        return <p data-testid="user-widget">drawn</p>;
    },
}));

vi.mock('./dashboard-actions', () => ({
    dashboardActions: { revertUserWidget: vi.fn() },
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

const PLACEMENT: PlacedUserWidget = {
    kind: 'user',
    id: 'placement-1',
    definitionId: 'def-1',
    size: { width: '1/2', height: 'm' },
};

const DEFINITION: UserWidgetDefinition = {
    id: 'def-1',
    name: 'Groceries by month',
    revision: 2,
    js: 'export default 1',
    manifest: { size: { width: '1/2', height: 'm' } },
    last_error: null,
    last_error_at: null,
    revisions: [],
};

const settle = vi.fn();
const RUN: UserWidgetsGate = { mode: 'run', settle };

function ready(
    data: Record<string, { status: number; body: unknown }>,
): UserWidgetDataState {
    return { status: 'ready', widgets: { 'def-1': { revision: 2, data } } };
}

function renderCell(overrides: Partial<UserWidgetCellProps> = {}) {
    return render(
        <UserWidgetCell
            placement={PLACEMENT}
            definition={DEFINITION}
            gate={RUN}
            data={ready({ rows: { status: 200, body: { data: [] } } })}
            caughtError={undefined}
            onError={vi.fn()}
            canRevert
            {...overrides}
        />,
    );
}

function cellState(): string | undefined {
    return document.querySelector<HTMLElement>('[data-user-widget]')?.dataset
        .state;
}

describe('<UserWidgetCell>', () => {
    it('draws the widget with the bodies of its calls', () => {
        renderCell();

        expect(screen.getByTestId('user-widget')).toBeTruthy();
        expect(drawn.props?.data).toEqual({ rows: { data: [] } });
        expect(drawn.props?.size).toEqual(PLACEMENT.size);
    });

    it('shows a skeleton while the data or the page decide', () => {
        renderCell({ data: { status: 'loading' } });
        expect(cellState()).toBe('loading');
    });

    it('waits for the definitions before drawing', () => {
        renderCell({ definition: undefined });
        expect(cellState()).toBe('loading');
        expect(screen.queryByLabelText(/Options for/)).toBeNull();
    });

    it('keeps a refused call to its own cell and counts it as settled', () => {
        settle.mockClear();
        renderCell({
            data: ready({
                rows: { status: 422, body: { message: 'Bad range.' } },
            }),
        });

        expect(screen.getByRole('alert').textContent).toContain(
            'rows: Bad range.',
        );
        expect(screen.queryByTestId('user-widget')).toBeNull();
        expect(settle).toHaveBeenCalledWith('placement-1');
    });

    it('says the data is partial when the gateway cut it', () => {
        renderCell({
            data: ready({
                rows: { status: 200, body: { data: [], truncated: true } },
            }),
        });

        expect(screen.getByText('Partial data')).toBeTruthy();
    });

    it('offers to remove the widget in safe mode without running it', () => {
        const onRemove = vi.fn();
        renderCell({
            gate: { mode: 'safe', reason: 'requested', exit: vi.fn() },
            onRemove,
        });

        expect(screen.queryByTestId('user-widget')).toBeNull();
        expect(screen.getByText('Groceries by month')).toBeTruthy();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remove from dashboard' }),
        );
        expect(onRemove).toHaveBeenCalled();
    });

    it('flags the menu when the current version raised an error', () => {
        renderCell({
            caughtError: {
                revision: 2,
                phase: 'render',
                message: 'boom',
                stack: null,
                componentStack: null,
                caughtAt: '2026-11-11T10:00:00Z',
            },
        });

        expect(
            screen
                .getByLabelText('Options for Groceries by month')
                .querySelector('.bg-destructive'),
        ).not.toBeNull();
    });
});
