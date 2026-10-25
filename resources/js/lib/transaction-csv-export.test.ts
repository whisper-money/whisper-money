import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    downloadTransactionsCsv,
    transactionCsvFilename,
} from './transaction-csv-export';

vi.mock('@/actions/App/Http/Controllers/TransactionExportController', () => ({
    default: { url: () => '/transactions/export' },
}));

function streamOf(...chunks: string[]): ReadableStream<Uint8Array> {
    const encoder = new TextEncoder();

    return new ReadableStream({
        start(controller) {
            chunks.forEach((chunk) =>
                controller.enqueue(encoder.encode(chunk)),
            );
            controller.close();
        },
    });
}

describe('downloadTransactionsCsv', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it('posts the filters and loaded ids, reports rows as they arrive and saves the file', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            body: streamOf(
                'Date,Description\n2026-01-02,A\n',
                '2026-01-01,B\n',
            ),
        });
        vi.stubGlobal('fetch', fetchMock);
        URL.createObjectURL = vi.fn(() => 'blob:csv');
        URL.revokeObjectURL = vi.fn();
        const click = vi
            .spyOn(HTMLAnchorElement.prototype, 'click')
            .mockImplementation(() => {});
        const onProgress = vi.fn();

        const download = await downloadTransactionsCsv({
            params: { search: 'coffee' },
            ids: ['tx-1'],
            onProgress,
        });

        expect(download).toEqual({
            filename: transactionCsvFilename(),
            rows: 2,
        });
        expect(fetchMock).toHaveBeenCalledWith(
            '/transactions/export',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ search: 'coffee', ids: ['tx-1'] }),
            }),
        );
        expect(onProgress.mock.calls).toEqual([[1], [2]]);
        expect(click).toHaveBeenCalledOnce();
    });

    it('counts a note that spans lines, split across chunks, as one row', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                body: streamOf(
                    'Date,Notes\n2026-01-02,"first line\nsecond ',
                    'line, ""quoted""\nthird"\n2026-01-01,plain\n',
                ),
            }),
        );
        URL.createObjectURL = vi.fn(() => 'blob:csv');
        URL.revokeObjectURL = vi.fn();
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(
            () => {},
        );
        const onProgress = vi.fn();

        const { rows } = await downloadTransactionsCsv({
            params: {},
            onProgress,
        });

        expect(rows).toBe(2);
        expect(onProgress.mock.calls).toEqual([[0], [2]]);
    });

    it('fails when the server refuses the export', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, status: 422, body: null }),
        );

        await expect(downloadTransactionsCsv({ params: {} })).rejects.toThrow(
            'Export failed with status 422',
        );
    });
});

describe('transactionCsvFilename', () => {
    it('names the file after the local date', () => {
        expect(transactionCsvFilename(new Date(2026, 9, 5))).toBe(
            'transactions-2026-10-05.csv',
        );
    });
});
