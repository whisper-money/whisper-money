import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { UnreadableRows } from './unreadable-rows';

describe('UnreadableRows', () => {
    it('says nothing when every row can be read', () => {
        const { container } = render(<UnreadableRows rows={[]} locale="en" />);

        expect(container).toBeEmptyDOMElement();
    });

    it('lists the first rows by their number in the file, and how many more', () => {
        const rows = Array.from({ length: 23 }, (_, index) => ({
            rowNumber: index + 2,
            reason: 'No date',
        }));

        render(<UnreadableRows rows={rows} locale="en" />);
        fireEvent.click(screen.getByText("23 rows can't be read"));

        expect(screen.getByText('Row 2')).toBeInTheDocument();
        expect(screen.getByText('Row 21')).toBeInTheDocument();
        expect(screen.queryByText('Row 22')).not.toBeInTheDocument();
        expect(screen.getByText('And 3 more.')).toBeInTheDocument();
    });

    it('speaks of a single row in the singular', () => {
        render(
            <UnreadableRows
                rows={[{ rowNumber: 9, reason: 'No amount' }]}
                locale="en"
            />,
        );

        expect(screen.getByText("1 row can't be read")).toBeInTheDocument();
    });
});
