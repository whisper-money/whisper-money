import { Button } from '@/components/ui/button';
import { act, render, screen } from '@testing-library/react';
import { hydrateRoot } from 'react-dom/client';
import { renderToString } from 'react-dom/server';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ShortcutKbd } from './shortcut-kbd';

function onPlatform(platform: string) {
    vi.spyOn(Navigator.prototype, 'platform', 'get').mockReturnValue(platform);
}

afterEach(() => {
    vi.restoreAllMocks();
});

describe('ShortcutKbd', () => {
    it('shows the catalog keys the Mac way on a Mac', () => {
        onPlatform('MacIntel');

        render(<ShortcutKbd id="transaction-dialog.save" />);

        expect(screen.getByText('⌘⏎')).toHaveAttribute('data-slot', 'kbd');
    });

    it('shows them with Ctrl on Windows and Linux', () => {
        onPlatform('Win32');

        render(<ShortcutKbd id="transaction-dialog.save" />);

        expect(screen.getByText('Ctrl ⏎')).toBeInTheDocument();
    });

    it('stays out of the accessible name of the button it sits in', () => {
        onPlatform('MacIntel');

        render(
            <Button>
                Save Changes
                <ShortcutKbd id="transaction-dialog.save" />
            </Button>,
        );

        expect(
            screen.getByRole('button', { name: 'Save Changes' }),
        ).toBeInTheDocument();
    });

    it('renders nothing on the server, then the chip once hydrated', async () => {
        onPlatform('MacIntel');
        const consoleError = vi
            .spyOn(console, 'error')
            .mockImplementation(() => {});
        const tree = (
            <button>
                Save
                <ShortcutKbd id="transaction-dialog.save" />
            </button>
        );

        // The server cannot know ⌘ from Ctrl, so it leaves the chip out.
        const html = renderToString(tree);
        expect(html).not.toContain('⌘');

        const container = document.createElement('div');
        container.innerHTML = html;
        document.body.appendChild(container);

        await act(async () => {
            hydrateRoot(container, tree, {
                onRecoverableError: (error) => {
                    throw error;
                },
            });
        });

        expect(container).toHaveTextContent('⌘⏎');
        expect(consoleError).not.toHaveBeenCalled();
    });
});
