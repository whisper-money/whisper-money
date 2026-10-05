import {
    bankMatches,
    context,
    show,
    start,
    store,
    storeChunk,
} from '@/actions/App/Http/Controllers/Api/FullImportController';
import { checkDuplicates } from '@/actions/App/Http/Controllers/Api/TransactionController';
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
export type UploadStage = 'create' | 'chunks' | 'start';

/**
 * A failed upload, with the phase it failed in: creating the import, sending
 * its rows, or handing it to the queue. The request's own error is `original`.
 */
export class ImportUploadError extends Error {
    constructor(
        public readonly stage: UploadStage,
        public readonly original: unknown,
    ) {
        super(`Full import upload failed while ${stage}`);
        this.name = 'ImportUploadError';
    }
}

async function inStage<T>(
    stage: UploadStage,
    run: () => Promise<T>,
): Promise<T> {
    try {
        return await run();
    } catch (error) {
        throw new ImportUploadError(stage, error);
    }
}

export async function submitImport(
    payload: ImportPayload,
    transactions: TransactionPayloadRow[],
    balances: BalancePayloadRow[],
    onProgress: (sent: number, total: number) => void,
): Promise<ImportStatus> {
    const { data: created } = await inStage('create', () =>
        axios.post<ImportStatus>(store.url(), payload),
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

            await inStage('chunks', () =>
                sendChunk(created.id, kind, offset / CHUNK_SIZE, chunk),
            );
            sent += chunk.length;
            onProgress(sent, total);
        }
    }

    const { data } = await inStage('start', () =>
        axios.post<ImportStatus>(start.url(created.id)),
    );

    return data;
}

/** The message a refused request carries, or a generic one. */
export function requestErrorMessage(error: unknown, fallback: string): string {
    if (error instanceof ImportUploadError) {
        return requestErrorMessage(error.original, fallback);
    }

    if (isAxiosError(error)) {
        const data = error.response?.data as
            | { message?: string; errors?: Record<string, string[]> }
            | undefined;
        const first = data?.errors ? Object.values(data.errors)[0]?.[0] : null;

        return first ?? data?.message ?? fallback;
    }

    return fallback;
}

/** Rows per duplicate check: well under the endpoint's own ceiling of 10,000. */
const DUPLICATE_CHECK_SIZE = 5000;

/**
 * Which of these rows the account already holds, one flag per row, by the
 * same rule the per-account import uses (same day, amount and description).
 * The server also skips rows by the file's own id, so this can only
 * undercount what will be left out.
 */
export async function checkExistingTransactions(
    accountId: string,
    rows: Pick<TransactionPayloadRow, 'date' | 'amount' | 'description'>[],
): Promise<boolean[]> {
    const flags: boolean[] = [];

    for (let offset = 0; offset < rows.length; offset += DUPLICATE_CHECK_SIZE) {
        const { data } = await axios.post<{ duplicates: boolean[] }>(
            checkDuplicates.url(),
            {
                account_id: accountId,
                transactions: rows
                    .slice(offset, offset + DUPLICATE_CHECK_SIZE)
                    .map((row) => ({
                        transaction_date: row.date,
                        amount: row.amount,
                        description: row.description,
                    })),
            },
        );

        flags.push(...data.duplicates);
    }

    return flags;
}
