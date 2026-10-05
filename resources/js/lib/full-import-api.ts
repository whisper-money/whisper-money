import {
    bankMatches,
    context,
    show,
    start,
    store,
    storeChunk,
} from '@/actions/App/Http/Controllers/Api/FullImportController';
import {
    type BalancePayloadRow,
    type BankLite,
    type FullImportContext,
    type ImportPayload,
    type ImportStatus,
    type TransactionPayloadRow,
} from '@/types/full-import';
import axios, { isAxiosError } from 'axios';

/** Rows per chunk: the most the server validates in one request. */
export const CHUNK_SIZE = 500;

/** How often the progress screen asks how the job is doing. */
export const POLL_INTERVAL_MS = 1500;

/** Tries per chunk before the upload gives up. */
const CHUNK_ATTEMPTS = 3;

export async function fetchImportContext(): Promise<FullImportContext> {
    const { data } = await axios.get<FullImportContext>(context.url());

    return data;
}

export async function fetchBankMatches(
    names: string[],
): Promise<Record<string, BankLite | null>> {
    if (names.length === 0) {
        return {};
    }

    const { data } = await axios.post<{
        matches: Record<string, BankLite | null>;
    }>(bankMatches.url(), { names });

    return data.matches;
}

export async function fetchImport(id: string): Promise<ImportStatus> {
    const { data } = await axios.get<ImportStatus>(show.url(id));

    return data;
}

async function sendChunk(
    importId: string,
    kind: 'transactions' | 'balances',
    position: number,
    rows: TransactionPayloadRow[] | BalancePayloadRow[],
): Promise<void> {
    for (let attempt = 1; ; attempt++) {
        try {
            await axios.post(storeChunk.url(importId), {
                kind,
                position,
                rows,
            });

            return;
        } catch (error) {
            // A refusal is the same refusal the second time; only a dropped
            // connection or a server hiccup is worth sending again. The chunk
            // is keyed on its position, so a resend never doubles it up.
            const status = isAxiosError(error) ? error.response?.status : 0;
            const retryable = !status || status >= 500;

            if (!retryable || attempt >= CHUNK_ATTEMPTS) {
                throw error;
            }
        }
    }
}

/**
 * Create the import, upload its rows in chunks and hand it to the queue.
 * `onProgress` hears how many rows are up, out of how many.
 */
export async function submitImport(
    payload: ImportPayload,
    transactions: TransactionPayloadRow[],
    balances: BalancePayloadRow[],
    onProgress: (sent: number, total: number) => void,
): Promise<ImportStatus> {
    const { data: created } = await axios.post<ImportStatus>(
        store.url(),
        payload,
    );
    const total = transactions.length + balances.length;
    let sent = 0;

    onProgress(sent, total);

    for (const [kind, rows] of [
        ['transactions', transactions],
        ['balances', balances],
    ] as const) {
        for (let offset = 0; offset < rows.length; offset += CHUNK_SIZE) {
            const chunk = rows.slice(offset, offset + CHUNK_SIZE);

            await sendChunk(created.id, kind, offset / CHUNK_SIZE, chunk);
            sent += chunk.length;
            onProgress(sent, total);
        }
    }

    const { data } = await axios.post<ImportStatus>(start.url(created.id));

    return data;
}

/** The message a refused request carries, or a generic one. */
export function requestErrorMessage(error: unknown, fallback: string): string {
    if (isAxiosError(error)) {
        const data = error.response?.data as
            | { message?: string; errors?: Record<string, string[]> }
            | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;

        return first ?? data?.message ?? fallback;
    }

    return fallback;
}
