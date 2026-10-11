import axios from 'axios';
import { toast } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { type ServerTransaction } from '@/types/transaction';
import {
    mergeReEvaluatedTransaction,
    reEvaluateTransactionRules,
} from './transaction-re-evaluation';

vi.mock('axios', () => ({
    default: { get: vi.fn(), post: vi.fn() },
}));

vi.mock('sonner', () => ({
    toast: {
        loading: vi.fn(() => 'toast-1'),
        success: vi.fn(),
        info: vi.fn(),
        error: vi.fn(),
    },
}));

vi.mock(
    '@/actions/App/Http/Controllers/ReEvaluateTransactionRulesController',
    () => ({
        bulk: () => ({ url: '/re-evaluate/bulk' }),
        status: ({ jobId }: { jobId: string }) => ({
            url: `/re-evaluate/status/${jobId}`,
        }),
    }),
);

function transaction(
    overrides: Partial<ServerTransaction> = {},
): ServerTransaction {
    return {
        id: 'transaction-1',
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'Coffee',
        transaction_date: '2026-05-11',
        amount: -450,
        currency_code: 'EUR',
        notes: null,
        source: 'imported',
        label_ids: [],
        created_at: '2026-05-11T00:00:00.000000Z',
        updated_at: '2026-05-11T00:00:00.000000Z',
        ...overrides,
    };
}

describe('mergeReEvaluatedTransaction', () => {
    it('derives label ids from returned labels', () => {
        const merged = mergeReEvaluatedTransaction(
            transaction(),
            transaction({
                labels: [
                    {
                        id: 'label-1',
                        user_id: 'user-1',
                        name: 'Work',
                        color: 'blue',
                        created_at: '2026-05-11T00:00:00.000000Z',
                        updated_at: '2026-05-11T00:00:00.000000Z',
                        deleted_at: null,
                    },
                ],
            }),
        );

        expect(merged.label_ids).toEqual(['label-1']);
    });

    it('updates plaintext notes for immediate display', () => {
        const merged = mergeReEvaluatedTransaction(
            transaction(),
            transaction({
                notes: 'Needs review',
            }),
        );

        expect(merged.notes).toBe('Needs review');
    });
});

function jobStatus(status: string, updated = 0) {
    return { data: { status, processed: 0, total: 10, updated } };
}

describe('reEvaluateTransactionRules', () => {
    const options = { intervalMs: 0, maxPendingTicks: 2 };

    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(axios.post).mockResolvedValue({ data: { job_id: 'job-1' } });
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('dispatches the selected transactions and resolves done when the job finishes', async () => {
        vi.mocked(axios.get)
            .mockResolvedValueOnce(jobStatus('pending'))
            .mockResolvedValueOnce(jobStatus('processing'))
            .mockResolvedValueOnce(jobStatus('done', 3));

        await expect(
            reEvaluateTransactionRules({ transaction_ids: ['t-1'] }, options),
        ).resolves.toBe('done');

        expect(axios.post).toHaveBeenCalledWith('/re-evaluate/bulk', {
            transaction_ids: ['t-1'],
        });
        expect(axios.get).toHaveBeenCalledWith('/re-evaluate/status/job-1');
        expect(toast.success).toHaveBeenCalledWith('Re-evaluation complete!', {
            id: 'toast-1',
            description: '3 transaction(s) updated.',
        });
        expect(toast.error).not.toHaveBeenCalled();
    });

    it('shows an error and resolves failed when the job fails', async () => {
        vi.mocked(axios.get).mockResolvedValueOnce(jobStatus('failed'));

        await expect(
            reEvaluateTransactionRules(undefined, options),
        ).resolves.toBe('failed');

        expect(toast.error).toHaveBeenCalledWith(
            'Failed to re-evaluate rules. Please try again.',
            { id: 'toast-1' },
        );
    });

    it('shows an error and resolves failed when a request fails', async () => {
        vi.mocked(axios.post).mockRejectedValueOnce(new Error('Network'));

        await expect(
            reEvaluateTransactionRules(undefined, options),
        ).resolves.toBe('failed');

        expect(toast.error).toHaveBeenCalled();
        expect(axios.get).not.toHaveBeenCalled();
    });

    it('tells the user the job is queued, without an error, when it is still pending after the wait', async () => {
        vi.mocked(axios.get).mockResolvedValue(jobStatus('pending'));

        await expect(
            reEvaluateTransactionRules(undefined, options),
        ).resolves.toBe('queued');

        expect(axios.get).toHaveBeenCalledTimes(3);
        expect(toast.loading).toHaveBeenCalledTimes(1);
        expect(toast.info).toHaveBeenCalledWith('Re-evaluation queued', {
            id: 'toast-1',
            description:
                'Your transactions will be updated in the background shortly. Reload the page later to see the changes.',
        });
        expect(toast.error).not.toHaveBeenCalled();
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('keeps waiting while the job is processing, however long it takes', async () => {
        vi.mocked(axios.get)
            .mockResolvedValueOnce(jobStatus('processing'))
            .mockResolvedValueOnce(jobStatus('processing'))
            .mockResolvedValueOnce(jobStatus('processing'))
            .mockResolvedValueOnce(jobStatus('done'));

        await expect(
            reEvaluateTransactionRules(undefined, options),
        ).resolves.toBe('done');
        expect(toast.info).not.toHaveBeenCalled();
        expect(toast.loading).toHaveBeenLastCalledWith(
            'Re-evaluating 0 of 10 transactions...',
            { id: 'toast-1' },
        );
    });
});
