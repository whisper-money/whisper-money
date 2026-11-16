import TransactionExportController from '@/actions/App/Http/Controllers/TransactionExportController';
import { getCsrfToken } from '@/lib/csrf';
import { saveBlob } from '@/lib/save-blob';
import { formatLocalDate } from '@/utils/date';

/** The transactions page's filters, as its query string carries them. */
export type TransactionExportParams = Record<string, string>;

export interface TransactionCsvExportRequest {
    params: TransactionExportParams;
    /** Only these rows, in the page's order; every match when left out. */
    ids?: string[];
    signal?: AbortSignal;
    /** Called as rows arrive with how many have been received so far. */
    onProgress?: (rows: number) => void;
    /**
     * Called once the response headers arrive with how many rows the file
     * will hold, when the server says: one per split line of a split
     * transaction, so it can exceed the transactions the list shows.
     */
    onTotal?: (rows: number) => void;
}

export interface TransactionCsvDownload {
    filename: string;
    /** Rows in the file, header left out: one per split line of a split transaction. */
    rows: number;
    /** Rows whose description is still encrypted on the device, written empty. */
    encryptedDescriptions: number;
}

/** Set by the server before the body starts, counted over the same rows. */
const ROWS_HEADER = 'X-Export-Rows';
const ENCRYPTED_DESCRIPTIONS_HEADER = 'X-Encrypted-Descriptions';

const NEWLINE = 10;
const QUOTE = 34;

/** The name the download is saved under: `transactions-YYYY-MM-DD.csv`. */
export function transactionCsvFilename(today: Date = new Date()): string {
    return `transactions-${formatLocalDate(today)}.csv`;
}

/**
 * Fetches the CSV export and hands it to the browser as a download. The body
 * is read chunk by chunk so the caller can show how far it has got.
 */
export async function downloadTransactionsCsv({
    params,
    ids,
    signal,
    onProgress,
    onTotal,
}: TransactionCsvExportRequest): Promise<TransactionCsvDownload> {
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

    const expectedRows = countHeader(response, ROWS_HEADER);

    if (expectedRows !== null) {
        onTotal?.(expectedRows);
    }

    const reader = response.body.getReader();
    const chunks: BlobPart[] = [];
    const countRows = createRowCounter();
    let rows = 0;

    for (;;) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        chunks.push(value as Uint8Array<ArrayBuffer>);
        rows = countRows(value);
        onProgress?.(rows);
    }

    const filename = transactionCsvFilename();
    saveBlob(new Blob(chunks, { type: 'text/csv;charset=utf-8' }), filename);

    return {
        filename,
        rows,
        encryptedDescriptions:
            countHeader(response, ENCRYPTED_DESCRIPTIONS_HEADER) ?? 0,
    };
}

/** A row count the server sent in a header, or `null` when it sent none. */
function countHeader(response: Response, name: string): number | null {
    const value = response.headers.get(name)?.trim();

    if (!value) {
        return null;
    }

    const count = Number(value);

    return Number.isInteger(count) && count >= 0 ? count : null;
}

/**
 * Counts the rows of a CSV fed to it chunk by chunk, header left out. A row
 * ends at a newline outside quotes; a note that spans lines sits inside a
 * quoted cell, and an escaped quote (`""`) flips the state twice, so it
 * cancels out. Both are single bytes in UTF-8, so a chunk boundary inside a
 * multi-byte character cannot hide one.
 */
function createRowCounter(): (chunk: Uint8Array) => number {
    let lines = 0;
    let quoted = false;

    return (chunk) => {
        for (const byte of chunk) {
            if (byte === QUOTE) {
                quoted = !quoted;
            } else if (byte === NEWLINE && !quoted) {
                lines++;
            }
        }

        return Math.max(0, lines - 1);
    };
}
