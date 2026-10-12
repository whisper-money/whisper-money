import {
    bulk as reEvaluateBulk,
    status as reEvaluateStatus,
} from '@/actions/App/Http/Controllers/ReEvaluateTransactionRulesController';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { toast } from 'sonner';

export function mergeReEvaluatedTransaction(
    transaction: ServerTransaction,
    updated: ServerTransaction,
): ServerTransaction {
    const labels = updated.labels ?? transaction.labels;
    const labelIds = updated.labels
        ? updated.labels.map((label) => label.id)
        : (updated.label_ids ?? transaction.label_ids);

    return {
        ...transaction,
        category_id: updated.category_id,
        category: updated.category,
        labels,
        label_ids: labelIds,
        notes: updated.notes ?? null,
    };
}

export type ReEvaluationOutcome = 'done' | 'queued' | 'failed';

interface ReEvaluationPollOptions {
    intervalMs?: number;
    /** Pending ticks to wait for before telling the user the job is queued. */
    maxPendingTicks?: number;
}

interface ReEvaluationStatus {
    status: 'pending' | 'processing' | 'done' | 'failed';
    processed: number;
    total: number;
    updated: number;
}

function wait(ms: number): Promise<void> {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function pollReEvaluation(
    jobId: string,
    toastId: string | number,
    { intervalMs = 1000, maxPendingTicks = 30 }: ReEvaluationPollOptions,
): Promise<ReEvaluationOutcome> {
    let pendingTicks = 0;

    for (;;) {
        const { data } = await axios.get<ReEvaluationStatus>(
            reEvaluateStatus({ jobId }).url,
        );

        if (data.status === 'done') {
            toast.success(__('Re-evaluation complete!'), {
                id: toastId,
                description: __(':count transaction(s) updated.', {
                    count: data.updated,
                }),
            });

            return 'done';
        }

        if (data.status === 'failed') {
            throw new Error('Re-evaluation job failed');
        }

        if (data.status === 'pending' && ++pendingTicks > maxPendingTicks) {
            // The shared queue is busy: the job is still queued and will run
            // later, so this is not a failure and retrying would duplicate it.
            toast.info(__('Re-evaluation queued'), {
                id: toastId,
                description: __(
                    'Your transactions will be updated in the background shortly. Reload the page later to see the changes.',
                ),
            });

            return 'queued';
        }

        if (data.status === 'processing') {
            toast.loading(
                __('Re-evaluating :processed of :total transactions...', {
                    processed: data.processed,
                    total: data.total,
                }),
                { id: toastId },
            );
        }

        await wait(intervalMs);
    }
}

/**
 * Dispatches the bulk re-evaluation job and follows its progress in a toast.
 * Never rejects: real failures (a failed job, an HTTP error) end in an error
 * toast and resolve to `'failed'`.
 */
export async function reEvaluateTransactionRules(
    payload?:
        | { transaction_ids: string[] }
        | { filters: Record<string, unknown> },
    options: ReEvaluationPollOptions = {},
): Promise<ReEvaluationOutcome> {
    const toastId = toast.loading(__('Re-evaluating transactions...'));

    try {
        const { data } = await axios.post<{ job_id: string }>(
            reEvaluateBulk().url,
            payload,
        );

        return await pollReEvaluation(data.job_id, toastId, options);
    } catch (error) {
        console.error('Failed to re-evaluate rules:', error);
        toast.error(__('Failed to re-evaluate rules. Please try again.'), {
            id: toastId,
        });

        return 'failed';
    }
}
