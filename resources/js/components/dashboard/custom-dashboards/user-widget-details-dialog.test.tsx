import { type UserWidgetDefinition } from '@/types/dashboard';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { dashboardActions } from './dashboard-actions';
import {
    currentWidgetError,
    UserWidgetDetailsDialog,
} from './user-widget-details-dialog';

vi.mock('./dashboard-actions', () => ({
    dashboardActions: { revertUserWidget: vi.fn() },
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

const DEFINITION: UserWidgetDefinition = {
    id: 'def-1',
    name: 'Groceries by month',
    revision: 3,
    js: '',
    manifest: { size: { width: '1/2', height: 'm' } },
    last_error: {
        revision: 3,
        phase: 'render',
        message: 'groceries.data is undefined',
        stack: null,
        component_stack: null,
    },
    last_error_at: '2026-11-10T09:30:00Z',
    revisions: [
        {
            number: 3,
            created_via: 'mcp',
            restored_from: null,
            created_at: '2026-11-10T09:00:00Z',
        },
        {
            number: 2,
            created_via: 'web',
            restored_from: 1,
            created_at: '2026-11-09T09:00:00Z',
        },
        {
            number: 1,
            created_via: 'mcp',
            restored_from: null,
            created_at: '2026-11-08T09:00:00Z',
        },
    ],
};

describe('currentWidgetError', () => {
    const caught = {
        revision: 3,
        phase: 'load' as const,
        message: 'caught here',
        stack: null,
        componentStack: null,
        caughtAt: '2026-11-11T10:00:00Z',
    };

    it('prefers the error caught on this page', () => {
        expect(currentWidgetError(DEFINITION, caught)?.message).toBe(
            'caught here',
        );
    });

    it('falls back to the stored one', () => {
        expect(currentWidgetError(DEFINITION, undefined)).toEqual({
            revision: 3,
            phase: 'render',
            message: 'groceries.data is undefined',
            stack: null,
            component_stack: null,
            at: '2026-11-10T09:30:00Z',
        });
    });

    it('ignores errors of an older version', () => {
        expect(
            currentWidgetError({ ...DEFINITION, revision: 4 }, caught),
        ).toBeNull();
    });
});

describe('<UserWidgetDetailsDialog>', () => {
    beforeEach(() => vi.mocked(dashboardActions.revertUserWidget).mockClear());

    function renderDialog(canRevert = true) {
        render(
            <UserWidgetDetailsDialog
                definition={DEFINITION}
                error={currentWidgetError(DEFINITION, undefined)}
                open
                onOpenChange={vi.fn()}
                canRevert={canRevert}
            />,
        );
    }

    it('shows the last error and asks the agent to fix it', () => {
        renderDialog();

        const error = screen.getByRole('alert');
        expect(error.textContent).toContain('groceries.data is undefined');
        expect(error.textContent).toContain('Version 3');
        expect(error.textContent).toContain('Ask your agent to fix it');
    });

    it('lists the versions, newest first, with the current one marked', () => {
        renderDialog();

        const rows = screen.getAllByTestId('user-widget-revision');
        expect(rows.map((row) => row.textContent)).toEqual([
            expect.stringContaining('Version 3Current'),
            expect.stringContaining('Back to version 1'),
            expect.stringContaining('Saved by your agent'),
        ]);
        expect(
            within(rows[0]).queryByRole('button', {
                name: 'Go back to this one',
            }),
        ).toBeNull();
    });

    it('goes back to a version once confirmed', () => {
        renderDialog();

        const [, , first] = screen.getAllByTestId('user-widget-revision');
        fireEvent.click(
            within(first).getByRole('button', { name: 'Go back to this one' }),
        );
        expect(dashboardActions.revertUserWidget).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Go back' }));
        expect(dashboardActions.revertUserWidget).toHaveBeenCalledWith(
            'def-1',
            1,
            expect.any(Function),
            expect.any(Function),
        );
    });

    it('offers no way back without the paid plan', () => {
        renderDialog(false);

        expect(
            screen.queryByRole('button', { name: 'Go back to this one' }),
        ).toBeNull();
    });
});
