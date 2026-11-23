import { render, screen } from '@testing-library/react';
import { Inbox } from 'lucide-react';
import { describe, expect, it } from 'vitest';
import {
    EmptyState,
    EmptyStateContent,
    EmptyStateDescription,
    EmptyStateHeader,
    EmptyStateMedia,
    EmptyStateTitle,
} from './empty-state';

describe('EmptyState', () => {
    it('renders a framed page-level state by default', () => {
        render(
            <EmptyState data-testid="empty">
                <EmptyStateDescription>Nothing here yet.</EmptyStateDescription>
            </EmptyState>,
        );

        const root = screen.getByTestId('empty');
        expect(root).toHaveAttribute('data-slot', 'empty-state');
        expect(root).toHaveAttribute('data-size', 'page');
        expect(root.className).toContain('border-dashed');
        expect(screen.getByText('Nothing here yet.')).toHaveAttribute(
            'data-slot',
            'empty-state-description',
        );
    });

    it('drops the frame when inline, inside a card or a table cell', () => {
        render(
            <EmptyState size="inline" data-testid="empty">
                <EmptyStateDescription>No labels found.</EmptyStateDescription>
            </EmptyState>,
        );

        const root = screen.getByTestId('empty');
        expect(root).toHaveAttribute('data-size', 'inline');
        expect(root.className).not.toContain('border-dashed');
    });

    it('lets the caller size and place it', () => {
        render(
            <EmptyState
                size="inline"
                data-testid="empty"
                className="hidden h-[300px] peer-empty:flex"
                style={{ height: 240 }}
            >
                <EmptyStateDescription>No data</EmptyStateDescription>
            </EmptyState>,
        );

        const root = screen.getByTestId('empty');
        // tailwind-merge lets the caller's `hidden` win over the base `flex`.
        expect(root.className).toContain('hidden');
        expect(root.className).not.toMatch(/(^|\s)flex(\s|$)/);
        expect(root).toHaveStyle({ height: '240px' });
    });

    it('composes an icon, a title, a description and actions', () => {
        render(
            <EmptyState>
                <EmptyStateHeader>
                    <EmptyStateMedia data-testid="media">
                        <Inbox />
                    </EmptyStateMedia>
                    <EmptyStateTitle>This dashboard is empty</EmptyStateTitle>
                    <EmptyStateDescription>
                        Add the widgets you want. <a href="/plans">See plans</a>
                    </EmptyStateDescription>
                </EmptyStateHeader>
                <EmptyStateContent>
                    <button type="button">Add widget</button>
                </EmptyStateContent>
            </EmptyState>,
        );

        expect(screen.getByTestId('media')).toHaveAttribute(
            'aria-hidden',
            'true',
        );
        expect(screen.getByText('This dashboard is empty')).toHaveAttribute(
            'data-slot',
            'empty-state-title',
        );
        expect(screen.getByRole('link', { name: 'See plans' })).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'Add widget' }).parentElement,
        ).toHaveAttribute('data-slot', 'empty-state-content');
    });

    it('renders the title as the page heading with asChild', () => {
        render(
            <EmptyState>
                <EmptyStateTitle asChild>
                    <h1>No Uncategorized Transactions</h1>
                </EmptyStateTitle>
            </EmptyState>,
        );

        const heading = screen.getByRole('heading', {
            level: 1,
            name: 'No Uncategorized Transactions',
        });
        expect(heading).toHaveAttribute('data-slot', 'empty-state-title');
    });

    it('offers a tile behind the icon', () => {
        render(
            <EmptyStateMedia variant="icon" data-testid="media">
                <Inbox />
            </EmptyStateMedia>,
        );

        const media = screen.getByTestId('media');
        expect(media).toHaveAttribute('data-variant', 'icon');
        expect(media.className).toContain('bg-muted');
    });
});
