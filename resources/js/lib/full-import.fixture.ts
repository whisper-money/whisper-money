import { type ImportStatus } from '@/types/full-import';

/** An import as the server reports it, mid-run unless told otherwise. */
export function importStatus(
    overrides: Partial<ImportStatus> = {},
): ImportStatus {
    return {
        id: 'import-1',
        source: 'banktrack',
        mode: 'add',
        status: 'processing',
        file_name: null,
        error: null,
        created_at: null,
        finished_at: null,
        undone_at: null,
        stats: {},
        ...overrides,
    };
}
