export interface CursorPaginatedResponse<T> {
    data: T[];
    next_cursor: string | null;
    next_page_url: string | null;
    prev_cursor: string | null;
    prev_page_url: string | null;
    per_page: number;
}

export function isCursorPaginatedResponse<T = unknown>(
    value: unknown,
): value is CursorPaginatedResponse<T> {
    if (!value || typeof value !== 'object') {
        return false;
    }

    const candidate = value as { data?: unknown };

    return Array.isArray(candidate.data);
}
