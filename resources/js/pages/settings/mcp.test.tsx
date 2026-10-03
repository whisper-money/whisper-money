import { fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import McpPage from './mcp';

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
    usePage: () => ({
        props: {
            auth: { hasProPlan: true },
            tokens: [],
            serverUrl: 'https://whisper.money/mcp',
            oauthUrl: 'https://whisper.money/mcp/oauth',
            subscribeUrl: '/subscribe',
            newToken: null,
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

describe('MCP settings page', () => {
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
});
