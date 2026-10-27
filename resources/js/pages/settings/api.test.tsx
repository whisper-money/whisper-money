import { type ConnectedAppRow } from '@/components/access-tokens/connected-app-list';
import { type AccessTokenRow } from '@/components/access-tokens/token-list';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ApiPage from './api';

const { PassThrough } = vi.hoisted(() => ({
    PassThrough: ({ children }: { children: React.ReactNode }) => children,
}));

vi.mock('@/layouts/app-layout', () => ({ default: PassThrough }));
vi.mock('@/layouts/settings/layout', () => ({ default: PassThrough }));

const page = vi.hoisted(() => ({
    props: {
        auth: { hasProPlan: true },
        tokens: [] as AccessTokenRow[],
        connectedApps: [] as ConnectedAppRow[],
        baseUrl: 'https://whisper.money/api/v1',
        docsUrl: '/documentation',
        subscribeUrl: '/subscribe',
        newToken: null as string | null,
    },
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    useForm: <T,>(data: T) => ({
        data,
        errors: {},
        processing: false,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
    }),
    router: { post: vi.fn(), delete: vi.fn() },
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    Head: () => null,
}));

function apiToken(overrides: Partial<AccessTokenRow>): AccessTokenRow {
    return {
        id: 1,
        name: 'Script',
        scope: 'read',
        created_at: null,
        last_used_at: null,
        expires_at: null,
        expired: false,
        ...overrides,
    };
}

describe('API settings page', () => {
    beforeEach(() => {
        page.props.auth.hasProPlan = true;
        page.props.tokens = [];
        page.props.connectedApps = [];
        page.props.newToken = null;
    });

    it('shows the base URL and links to the documentation', () => {
        render(<ApiPage />);

        expect(screen.getByText('https://whisper.money/api/v1')).toBeTruthy();
        expect(screen.getByText('Read the API documentation')).toBeTruthy();
        expect(screen.getByText('You have no tokens yet.')).toBeTruthy();
    });

    it('shows a freshly created token once', () => {
        page.props.newToken = 'wm_api_secret';

        render(<ApiPage />);

        expect(screen.getByText('wm_api_secret')).toBeTruthy();
        expect(screen.getByText('Copy your new token now')).toBeTruthy();
    });

    it('marks an expired token and does not offer rotating it', () => {
        page.props.tokens = [
            apiToken({
                name: 'Old script',
                created_at: '2026-01-01T00:00:00+00:00',
                expires_at: '2026-01-31T00:00:00+00:00',
                expired: true,
            }),
        ];

        render(<ApiPage />);

        expect(screen.getByText('Old script')).toBeTruthy();
        expect(screen.getAllByText('Expired').length).toBeGreaterThan(0);
        expect(screen.queryByRole('button', { name: 'Rotate' })).toBeNull();
    });

    it('upsells free accounts, who cannot create or rotate tokens', () => {
        page.props.auth.hasProPlan = false;
        page.props.tokens = [apiToken({ name: 'Lapsed', scope: 'read_write' })];

        render(<ApiPage />);

        expect(screen.getByText('This is a Pro feature')).toBeTruthy();
        expect(
            (
                screen.getByRole('button', {
                    name: 'Create token',
                }) as HTMLButtonElement
            ).disabled,
        ).toBe(true);
        expect(screen.queryByRole('button', { name: 'Rotate' })).toBeNull();
        expect(screen.getByRole('button', { name: 'Revoke' })).toBeTruthy();
    });

    it('lists the connected apps with when each was last used', () => {
        page.props.connectedApps = [
            {
                id: 'client-1',
                name: 'Budget Sync',
                scopes: ['api:use'],
                connected_at: '2026-10-01T00:00:00+00:00',
                last_used_at: null,
            },
        ];

        render(<ApiPage />);

        expect(screen.getByText('Connected apps')).toBeTruthy();
        expect(screen.getByText('Budget Sync')).toBeTruthy();
        expect(screen.getByText('api:use')).toBeTruthy();
        expect(screen.getByText(/Last used/)).toBeTruthy();
    });
});
