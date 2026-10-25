import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionExportButton } from './transaction-export-button';

vi.mock('@/actions/App/Http/Controllers/Api/TransactionController', () => ({
    count: {
        url: ({ query }: { query: Record<string, string> }) =>
            `/api/transactions/count?${new URLSearchParams(query)}`,
    },
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

const { toastSuccess, downloadTransactionsCsv } = vi.hoisted(() => ({
    toastSuccess: vi.fn(),
    downloadTransactionsCsv: vi.fn(),
}));

vi.mock('sonner', () => ({
    toast: { error: vi.fn(), success: toastSuccess },
}));

vi.mock('@/lib/transaction-csv-export', () => ({ downloadTransactionsCsv }));

const params = { search: 'coffee', category_ids: 'cat-1' };

function renderButton({
    hasMore,
    loadedIds = ['tx-1', 'tx-2'],
}: {
    hasMore: boolean;
    loadedIds?: string[];
}) {
    return render(
        <TransactionExportButton
            params={params}
            loadedIds={loadedIds}
            hasMore={hasMore}
            filterChips={['“coffee”', 'Groceries']}
        />,
    );
}

function mockCount(count: number) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ count }),
        }),
    );
}

describe('TransactionExportButton', () => {
    beforeEach(() => {
        downloadTransactionsCsv.mockResolvedValue({
            filename: 'transactions-2026-10-25.csv',
            rows: 2,
        });
    });

    afterEach(() => {
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('downloads straight away when the list holds every match', async () => {
        renderButton({ hasMore: false });

        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));

        await waitFor(() =>
            expect(toastSuccess).toHaveBeenCalledWith('CSV downloaded', {
                description: '2 transactions · transactions-2026-10-25.csv',
            }),
        );
        expect(downloadTransactionsCsv).toHaveBeenCalledWith(
            expect.objectContaining({ params, ids: undefined }),
        );
        expect(
            screen.queryByTestId('transaction-export-dialog'),
        ).not.toBeInTheDocument();
    });

    it('asks first when the list has more pages, defaulting to every match', async () => {
        mockCount(1234);
        renderButton({ hasMore: true });

        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));

        const dialog = await screen.findByTestId('transaction-export-dialog');
        expect(within(dialog).getByText('“coffee”')).toBeInTheDocument();
        expect(within(dialog).getByText('Groceries')).toBeInTheDocument();
        expect(
            await within(dialog).findByRole('button', {
                name: 'Download 1,234 transactions',
            }),
        ).toBeInTheDocument();
        expect(
            within(dialog).getByRole('radio', { name: /All matching/ }),
        ).toBeChecked();
        expect(fetch).toHaveBeenCalledWith(
            '/api/transactions/count?search=coffee&category_ids=cat-1',
            expect.anything(),
        );
        expect(downloadTransactionsCsv).not.toHaveBeenCalled();
    });

    it('counts the call to action from the selected option', async () => {
        mockCount(1234);
        renderButton({ hasMore: true });
        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
        const dialog = await screen.findByTestId('transaction-export-dialog');
        await within(dialog).findByRole('button', {
            name: 'Download 1,234 transactions',
        });

        fireEvent.click(
            within(dialog).getByRole('radio', { name: /Only loaded/ }),
        );
        fireEvent.click(
            within(dialog).getByRole('button', {
                name: 'Download 2 transactions',
            }),
        );

        await waitFor(() =>
            expect(downloadTransactionsCsv).toHaveBeenCalledWith(
                expect.objectContaining({ params, ids: ['tx-1', 'tx-2'] }),
            ),
        );
        await waitFor(() =>
            expect(
                screen.queryByTestId('transaction-export-dialog'),
            ).not.toBeInTheDocument(),
        );
    });

    it('shows how far the export has got and aborts it on cancel', async () => {
        mockCount(300);
        let signal: AbortSignal | undefined;
        downloadTransactionsCsv.mockImplementation(
            ({
                signal: given,
                onProgress,
            }: {
                signal: AbortSignal;
                onProgress: (rows: number) => void;
            }) => {
                signal = given;
                onProgress(120);

                return new Promise((_, reject) =>
                    given.addEventListener('abort', () =>
                        reject(new DOMException('Aborted', 'AbortError')),
                    ),
                );
            },
        );
        renderButton({ hasMore: true });
        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
        const dialog = await screen.findByTestId('transaction-export-dialog');

        fireEvent.click(
            await within(dialog).findByRole('button', {
                name: 'Download 300 transactions',
            }),
        );

        expect(
            await within(dialog).findByText('Preparing 120 of 300…'),
        ).toBeInTheDocument();
        expect(within(dialog).getByRole('progressbar')).toBeInTheDocument();

        await act(async () => {
            fireEvent.click(
                within(dialog).getByRole('button', { name: 'Cancel' }),
            );
        });

        expect(signal?.aborted).toBe(true);
        expect(toastSuccess).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(
                screen.queryByTestId('transaction-export-dialog'),
            ).not.toBeInTheDocument(),
        );
    });

    it('shows an open-ended progress when the count could not be had', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({ ok: false, json: () => ({}) }),
        );
        downloadTransactionsCsv.mockImplementation(
            ({ onProgress }: { onProgress: (rows: number) => void }) => {
                onProgress(40);

                return new Promise(() => {});
            },
        );
        renderButton({ hasMore: true });
        fireEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
        const dialog = await screen.findByTestId('transaction-export-dialog');

        fireEvent.click(
            await within(dialog).findByRole('button', { name: 'Download' }),
        );

        expect(
            await within(dialog).findByText('Preparing 40…'),
        ).toBeInTheDocument();
    });

    it('cannot export an empty, fully loaded list', () => {
        renderButton({ hasMore: false, loadedIds: [] });

        expect(
            screen.getByRole('button', { name: 'Export CSV' }),
        ).toBeDisabled();
    });
});
