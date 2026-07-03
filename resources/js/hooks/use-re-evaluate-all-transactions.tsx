import {
    bulk as reEvaluateBulk,
    status as reEvaluateStatus,
} from '@/actions/App/Http/Controllers/ReEvaluateTransactionRulesController';
import axios from 'axios';
import { useCallback } from 'react';
import { toast } from 'sonner';

export function useReEvaluateAllTransactions() {
    const reEvaluateAll = useCallback(async () => {
        const toastId = toast.loading(`Re-evaluating 0 of ... transactions...`);

        try {
            const bulkResponse = await axios.post<{ job_id: string }>(
                reEvaluateBulk().url,
            );

            const jobId = bulkResponse.data.job_id;

            await new Promise<void>((resolve, reject) => {
                let pendingTicks = 0;
                const poll = async () => {
                    try {
                        const statusResponse = await axios.get<{
                            status: string;
                            processed: number;
                            total: number;
                            updated: number;
                        }>(reEvaluateStatus({ jobId }).url);

                        const { status, processed, total, updated } =
                            statusResponse.data;

                        toast.loading(
                            `Re-evaluating ${processed} of ${total} transactions...`,
                            { id: toastId },
                        );

                        if (status === 'done') {
                            toast.dismiss(toastId);
                            toast.success(() => (
                                <div>
                                    {`Re-evaluation complete!`}
                                    <br />
                                    {`${updated} transaction(s) updated.`}
                                </div>
                            ));
                            resolve();
                        } else if (status === 'failed') {
                            reject(new Error('Job failed'));
                        } else if (
                            status === 'pending' &&
                            ++pendingTicks > 30
                        ) {
                            // The job never started (e.g. no queue worker
                            // running) — give up instead of polling forever.
                            reject(new Error('Job did not start'));
                        } else {
                            setTimeout(poll, 1000);
                        }
                    } catch (error) {
                        reject(error);
                    }
                };

                poll();
            });
        } catch (error) {
            console.error('Failed to re-evaluate transactions:', error);
            toast.error(
                'Failed to re-evaluate transactions. Please try again.',
                { id: toastId },
            );
            throw error;
        }
    }, []);

    return { reEvaluateAll };
}
