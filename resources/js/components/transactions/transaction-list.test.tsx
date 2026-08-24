import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { TransactionListSkeleton } from './transaction-list';

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
});
