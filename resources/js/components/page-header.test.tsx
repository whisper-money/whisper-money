import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PageHeader } from './page-header';

describe('PageHeader', () => {
    it('renders the title as the page h1', () => {
        render(<PageHeader title="Accounts" />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Accounts' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('banner')).toHaveAttribute(
            'data-slot',
            'page-header',
        );
    });

    it('renders a string description as a muted paragraph', () => {
        render(
            <PageHeader
                title="Accounts"
                description="View and manage your bank accounts"
            />,
        );

        const description = screen.getByText(
            'View and manage your bank accounts',
        );
        expect(description.tagName).toBe('P');
        expect(description).toHaveClass('text-muted-foreground');
    });

    it('renders a node description as is, in muted text', () => {
        render(
            <PageHeader
                title="Groceries"
                description={<span data-testid="meta">Tracking Food</span>}
            />,
        );

        const meta = screen.getByTestId('meta');
        expect(meta.parentElement).toHaveClass('text-muted-foreground');
        expect(meta.parentElement?.tagName).toBe('DIV');
    });

    it('renders the media before the title and the actions after it', () => {
        render(
            <PageHeader
                title="Savings"
                media={<img alt="Bank logo" />}
                actions={<button type="button">Edit</button>}
            />,
        );

        const logo = screen.getByRole('img', { name: 'Bank logo' });
        const heading = screen.getByRole('heading', { level: 1 });
        const action = screen.getByRole('button', { name: 'Edit' });

        expect(
            logo.compareDocumentPosition(heading) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
        expect(
            heading.compareDocumentPosition(action) &
                Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it('omits the description and actions when not given', () => {
        const { container } = render(<PageHeader title="Progress" />);

        expect(container.querySelector('p')).toBeNull();
        expect(screen.getByRole('banner').children).toHaveLength(1);
    });

    it('omits the actions slot when the actions are false', () => {
        render(<PageHeader title="Notifications" actions={false} />);

        expect(screen.getByRole('banner').children).toHaveLength(1);
    });

    it('merges a className onto the header', () => {
        render(<PageHeader title="Transactions" className="items-center" />);

        expect(screen.getByRole('banner')).toHaveClass('items-center');
    });
});
