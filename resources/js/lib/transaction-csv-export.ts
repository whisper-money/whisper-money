import TransactionExportController from '@/actions/App/Http/Controllers/TransactionExportController';
import { getCsrfToken } from '@/lib/csrf';
import { formatLocalDate } from '@/utils/date';

export interface TransactionCsvExportRequest {
    /** The page's filters and sort, as its query string carries them. */
    params: Record<string, string>;
    /** Only these rows, in the page's order; every match when left out. */
    ids?: string[];
    signal?: AbortSignal;
    /** Called as rows arrive with how many have been received so far. */
    onProgress?: (rows: number) => void;
}

const NEWLINE = 10;

/** The name the download is saved under: `transactions-YYYY-MM-DD.csv`. */
export function transactionCsvFilename(today: Date = new Date()): string {
    return `transactions-${formatLocalDate(today)}.csv`;
}

/**
 * Fetches the CSV export and hands it to the browser as a download. The body
 * is read chunk by chunk so the caller can show how far it has got: every row
 * ends in a newline, so newlines past the header count rows. A quoted cell
 * that holds a newline counts one extra, which only ever runs the progress a
 * little early, so callers cap it at the total they expect.
 */
export async function downloadTransactionsCsv({
    params,
    ids,
    signal,
    onProgress,
}: TransactionCsvExportRequest): Promise<string> {
    const response = await fetch(TransactionExportController.url(), {
        method: 'POST',
        headers: {
            Accept: 'text/csv',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        body: JSON.stringify(ids ? { ...params, ids } : params),
        signal,
    });

    if (!response.ok || !response.body) {
        throw new Error(`Export failed with status ${response.status}`);
    }

    const reader = response.body.getReader();
    const chunks: BlobPart[] = [];
    let newlines = 0;

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        chunks.push(value as Uint8Array<ArrayBuffer>);
        newlines += value.reduce(
            (count, byte) => (byte === NEWLINE ? count + 1 : count),
            0,
        );
        onProgress?.(Math.max(0, newlines - 1));
    }

    const filename = transactionCsvFilename();
    saveBlob(new Blob(chunks, { type: 'text/csv;charset=utf-8' }), filename);

    return filename;
}

function saveBlob(blob: Blob, filename: string): void {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');

    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
}
