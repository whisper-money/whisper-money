import { type ConnectedAppRow } from '@/components/access-tokens/connected-app-list';
import { pricingFixture } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import McpPage from './mcp';

vi.mock('@/lib/posthog', () => ({ captureEvent: vi.fn() }));

const pageProps = vi.hoisted(() => ({
    auth: { hasProPlan: true, user: { id: 'user-1' } },
    pricing: null as unknown as typeof pricingFixture,
    locale: 'en',
    tokens: [],
    connectedApps: [] as ConnectedAppRow[],
    serverUrl: 'https://whisper.money/mcp',
    oauthUrl: 'https://whisper.money/mcp/oauth',
    newToken: null as string | null,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    router: { post: vi.fn(), delete: vi.fn() },
    useForm: () => ({
        data: { name: '', scope: 'read' },
        setData: vi.fn(),
        post: vi.fn(),
        processing: false,
        errors: {},
    }),
    usePage: () => ({ props: pageProps }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

describe('MCP settings page', () => {
    beforeEach(() => {
        pageProps.newToken = null;
        pageProps.connectedApps = [];
        pageProps.auth.hasProPlan = true;
        pageProps.pricing = pricingFixture;
    });

    it('upsells a free account and opens the upgrade dialog in place, attributed to MCP', () => {
        pageProps.auth.hasProPlan = false;

        render(<McpPage />);

        expect(
            screen.getByText('Connect assistants with Standard'),
        ).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'See plans' }));

        expect(
            screen.getByText(
                'Create tokens to connect AI assistants to your data.',
            ),
        ).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: /Start Standard — free for/ })
                .getAttribute('href'),
        ).toContain('source=mcp');
    });

    it('lists the assistants connected through OAuth', () => {
        pageProps.connectedApps = [
            {
                id: 'client-1',
                name: 'ChatGPT',
                verified: true,
                redirect: 'https://chatgpt.com',
                scopes: ['AI Connector'],
                connected_at: '2026-10-01T00:00:00+00:00',
                last_used_at: null,
            },
        ];

        render(<McpPage />);

        expect(screen.getByText('Connected apps')).toBeTruthy();
        expect(screen.getByText('Verified')).toBeTruthy();
        expect(screen.getByText('https://chatgpt.com')).toBeTruthy();
        expect(screen.queryByText(/Last used/)).toBeNull();
    });

    it('walks through adding Whisper Money as a Perplexity connector', () => {
        render(<McpPage />);

        expect(screen.queryByText(/In Perplexity, open Plugins/)).toBeNull();

        fireEvent.click(screen.getByRole('radio', { name: 'Perplexity' }));

        expect(screen.getByText(/In Perplexity, open Plugins/)).toBeTruthy();
        expect(
            screen.getByText(/pick OAuth as the authentication/),
        ).toBeTruthy();
        expect(
            screen.getByText('https://whisper.money/mcp/oauth'),
        ).toBeTruthy();
        expect(
            screen.queryByText("Whisper Money is in ChatGPT's app directory"),
        ).toBeNull();
    });

    it('offers the Claude Code plugin before the token command', () => {
        render(<McpPage />);

        fireEvent.click(
            screen.getByRole('button', { name: /Connect with Claude Code/ }),
        );

        const commands = [
            '/plugin marketplace add whisper-money/whisper-money',
            '/plugin install whisper-money@whisper-money',
            'claude mcp add --transport http whisper-money https://whisper.money/mcp --header "Authorization: Bearer <token>"',
        ].map((command) => screen.getByText(command));

        commands.slice(1).forEach((command, index) => {
            expect(
                commands[index].compareDocumentPosition(command) &
                    Node.DOCUMENT_POSITION_FOLLOWING,
            ).toBeTruthy();
        });
        expect(screen.getByText(/Then run \/mcp/)).toBeTruthy();
    });

    it('shows a freshly created token once, with the Claude Code section open', () => {
        pageProps.newToken = 'wm_secret_token';

        render(<McpPage />);

        expect(screen.getByText('wm_secret_token')).toBeTruthy();
        expect(screen.getByText('With the plugin')).toBeTruthy();
    });
});
