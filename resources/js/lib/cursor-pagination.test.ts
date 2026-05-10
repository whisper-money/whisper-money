import { describe, expect, it } from 'vitest';
import { isCursorPaginatedResponse } from './cursor-pagination';

describe('isCursorPaginatedResponse', () => {
    it('accepts cursor-paginated Inertia props', () => {
        expect(
            isCursorPaginatedResponse({
                data: [],
                next_cursor: null,
                next_page_url: null,
                prev_cursor: null,
                prev_page_url: null,
                per_page: 50,
            }),
        ).toBe(true);
    });

    it('rejects collection props from non-index pages', () => {
        expect(isCursorPaginatedResponse([])).toBe(false);
        expect(isCursorPaginatedResponse({ transactions: [] })).toBe(false);
        expect(isCursorPaginatedResponse(undefined)).toBe(false);
    });
});
