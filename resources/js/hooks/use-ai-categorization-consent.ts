import { usePollJobStatus } from '@/hooks/use-poll-job-status';
import { status as categorizationStatus } from '@/routes/ai/categorization';
import {
    dismiss as dismissConsent,
    store as storeConsent,
} from '@/routes/ai/consent';
import { type SharedData } from '@/types';
import {
    type AiConsentResponse,
    type CategorizationProgress,
} from '@/types/categorization';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

/**
 * The prompt on /transactions to let AI categorize the user's transactions,
 * and the backfill it starts: the rows it is working on spin until each one
 * lands, and the listed rows are refreshed as it goes.
 */
export function useAiCategorizationConsent({
    rows,
    refreshRows,
    hasAiConsent,
    aiConsentPromptDismissed,
}: {
    /** The rows on screen, to spin the uncategorized ones. */
    rows: ServerTransaction[];
    /** Pulls in what AI has applied so far. */
    refreshRows: () => void;
    hasAiConsent: boolean;
    aiConsentPromptDismissed: boolean;
}) {
    const { auth } = usePage<SharedData>().props;
    const [isResolved, setIsResolved] = useState(false);
    const [isSaving, setIsSaving] = useState(false);
    // Never on a shared account: consent means nothing when everybody holds the
    // credentials, and the route behind the banner is blocked, so offering it
    // would only hand a journalist a broken button mid-demo.
    const showBanner =
        auth.hasProPlan &&
        !auth.isSharedAccount &&
        !hasAiConsent &&
        !aiConsentPromptDismissed &&
        !isResolved;
    const [categorizingIds, setCategorizingIds] = useState<Set<string>>(
        () => new Set(),
    );
    const categorizationToastRef = useRef<string | number | undefined>(
        undefined,
    );
    const { start: startCategorizationPoll } = usePollJobStatus();

    // Clear the spinner for any row AI has finished categorizing.
    useEffect(() => {
        setCategorizingIds((previous) => {
            if (previous.size === 0) {
                return previous;
            }

            let changed = false;
            const next = new Set(previous);
            for (const transaction of rows) {
                if (next.has(transaction.id) && transaction.category_id) {
                    next.delete(transaction.id);
                    changed = true;
                }
            }

            return changed ? next : previous;
        });
    }, [rows]);

    const pollCategorizationStatus = useCallback(
        (jobId: string, fallbackTotal: number) => {
            let pendingTicks = 0;

            const dismiss = () => {
                setCategorizingIds(new Set());
                toast.dismiss(categorizationToastRef.current);
                categorizationToastRef.current = undefined;
            };

            startCategorizationPoll(async () => {
                let data;
                try {
                    ({ data } = await axios.get<CategorizationProgress>(
                        categorizationStatus(jobId).url,
                    ));
                } catch {
                    dismiss();
                    return 'stop';
                }

                // The job never started (e.g. no queue worker running) — give up
                // instead of polling forever.
                if (data.status === 'pending' && ++pendingTicks > 15) {
                    dismiss();
                    return 'stop';
                }

                const total = data.total || fallbackTotal;
                categorizationToastRef.current = toast.loading(
                    __('Categorizing :processed of :total transactions…', {
                        processed: data.processed,
                        total,
                    }),
                    { id: categorizationToastRef.current },
                );

                // Pull whatever AI has applied so far into the visible rows.
                if (data.status === 'processing' || data.status === 'done') {
                    refreshRows();
                }

                if (data.status === 'done' || data.status === 'failed') {
                    setCategorizingIds(new Set());
                    if (data.status === 'failed') {
                        toast.error(
                            __('AI categorization failed. Please try again.'),
                            { id: categorizationToastRef.current },
                        );
                    } else if (data.applied > 0) {
                        toast.success(
                            __('AI categorized :count transactions', {
                                count: data.applied,
                            }),
                            { id: categorizationToastRef.current },
                        );
                    } else {
                        toast.dismiss(categorizationToastRef.current);
                    }
                    categorizationToastRef.current = undefined;
                    return 'stop';
                }

                return 'continue';
            }, 2000);
        },
        [startCategorizationPoll, refreshRows],
    );

    const enable = useCallback(async () => {
        setIsSaving(true);
        try {
            const { data } = await axios.post<AiConsentResponse>(
                storeConsent.url(),
            );
            setIsResolved(true);
            toast.success(
                __(
                    'AI enabled. You can change this anytime in Settings > Manage Plan.',
                ),
            );

            if (data.categorization) {
                // Spin every currently-visible uncategorized row while the
                // backfill runs; the prune effect clears each as it lands.
                setCategorizingIds(
                    new Set(
                        rows
                            .filter((transaction) => !transaction.category_id)
                            .map((transaction) => transaction.id),
                    ),
                );
                categorizationToastRef.current = toast.loading(
                    __('Categorizing :processed of :total transactions…', {
                        processed: 0,
                        total: data.categorization.total,
                    }),
                );
                pollCategorizationStatus(
                    data.categorization.job_id,
                    data.categorization.total,
                );
            }
        } catch {
            toast.error(__('Something went wrong.'));
        } finally {
            setIsSaving(false);
        }
    }, [rows, pollCategorizationStatus]);

    const dismiss = useCallback(() => {
        setIsResolved(true);
        axios.post(dismissConsent.url()).catch(() => {
            // Best-effort: the banner is already hidden for this session.
        });
        toast(
            __(
                'AI not enabled. You can turn it on anytime in Settings > Manage Plan.',
            ),
        );
    }, []);

    return { showBanner, isSaving, categorizingIds, enable, dismiss };
}
