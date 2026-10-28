import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ConnectedAppList, type ConnectedAppRow } from './connected-app-list';

const { router } = vi.hoisted(() => ({
    router: { delete: vi.fn() },
}));

vi.mock('@inertiajs/react', () => ({ router }));

function app(overrides: Partial<ConnectedAppRow> = {}): ConnectedAppRow {
    return {
        id: 'client-1',
        name: 'Claude',
        redirect: 'https://chatgpt.com',
        scopes: ['AI Connector'],
        connected_at: '2026-10-01T00:00:00+00:00',
        last_used_at: '2026-10-20T00:00:00+00:00',
        ...overrides,
    };
}

function renderList(apps: ConnectedAppRow[], showLastUsed = false) {
    return render(
        <ConnectedAppList
            apps={apps}
            description="Apps you connected."
            destroyUrl={(id) => `/settings/mcp/apps/${id}`}
            showLastUsed={showLastUsed}
        />,
    );
}

describe('ConnectedAppList', () => {
    beforeEach(() => router.delete.mockReset());

    it('says so when no app is connected', () => {
        renderList([]);

        expect(screen.getByText('No apps are connected.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Revoke' })).toBeNull();
    });

    it('shows each app with its scopes and when it connected', () => {
        renderList([app(), app({ id: 'client-2', name: 'ChatGPT' })]);

        expect(screen.getByText('Claude')).toBeTruthy();
        expect(screen.getByText('ChatGPT')).toBeTruthy();
        expect(screen.getAllByText('AI Connector')).toHaveLength(2);
        expect(screen.getAllByText('https://chatgpt.com')).toHaveLength(2);
        expect(screen.getAllByText(/Connected/).length).toBeGreaterThan(0);
        expect(screen.queryByText(/Last used/)).toBeNull();
    });

    it('shows when an app was last used where the screen knows it', () => {
        renderList([app({ last_used_at: null })], true);

        expect(screen.getByText(/Last used: Never/)).toBeTruthy();
    });

    it('disconnects an app only once the user confirms', () => {
        renderList([app()]);

        fireEvent.click(screen.getByRole('button', { name: 'Revoke' }));
        expect(router.delete).not.toHaveBeenCalled();
        expect(screen.getByText('Disconnect this app?')).toBeTruthy();

        // The open dialog hides the trigger, leaving its confirm button.
        fireEvent.click(screen.getByRole('button', { name: 'Revoke' }));
        expect(router.delete).toHaveBeenCalledWith(
            '/settings/mcp/apps/client-1',
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
