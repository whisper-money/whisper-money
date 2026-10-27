import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { TransactionListSkeleton } from './transaction-list-skeleton';

describe('TransactionListSkeleton', () => {
    it('renders the table-shaped skeleton shared by the deferred fallback and the internal loading state', () => {
        const { container } = render(<TransactionListSkeleton />);

        // The table-shaped skeleton is wrapped in a bordered container, which is
        // what distinguishes it from the old plain-bars fallback. Using the same
        // component in both loading phases is what removes the double-skeleton jump.
        expect(
            container.querySelector('.overflow-hidden.rounded-md.border'),
        ).not.toBeNull();

        // Far more placeholders than the old 8-bar fallback: header row, section
        // header, and six body rows, each mimicking the real table layout.
        expect(
            container.querySelectorAll('[data-slot="skeleton"]').length,
        ).toBeGreaterThan(8);
    });

    it('draws the compact list on a phone, in the inset frame of the list, unless it is in a card', () => {
        const { container, unmount } = render(<TransactionListSkeleton />);
        const frame = container.querySelector('.rounded-md.border')!;

        expect(frame.className).toContain('max-md:-mx-2');
        expect(frame.className).toContain('max-md:rounded-b-none');
        expect(
            screen.getByTestId('transaction-skeleton-list').className,
        ).toContain('md:hidden');
        expect(
            screen.getByTestId('transaction-skeleton-table').className,
        ).toContain('max-md:hidden');
        unmount();

        const framed = render(<TransactionListSkeleton framedOnPhone />);

        expect(
            framed.container.querySelector('.rounded-md.border')!.className,
        ).not.toContain('max-md:');
    });
});
