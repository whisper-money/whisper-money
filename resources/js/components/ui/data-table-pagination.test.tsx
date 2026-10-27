import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { DataTablePagination } from './data-table-pagination';

describe('DataTablePagination', () => {
    it('shows how many rows are on screen out of the total', () => {
        render(<DataTablePagination displayedCount={4} total={10} />);

        expect(screen.getByText(/4 of 10/)).toBeTruthy();
    });

    it('prints nothing for an empty list rather than a stray zero', () => {
        const { container } = render(
            <DataTablePagination displayedCount={0} total={0} />,
        );

        expect(container.textContent).toBe('');
    });
});
