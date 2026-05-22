import {
    apply as applyRoute,
    matches as matchesRoute,
    status as statusRoute,
} from '@/actions/App/Http/Controllers/Settings/AutomationRuleApplicationController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import type { AutomationRule } from '@/types/automation-rule';
import type { ServerTransaction } from '@/types/transaction';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { format, parseISO } from 'date-fns';
import { ArrowLeft, ArrowRight, Loader2, Sparkles } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type ApplyResult =
    | {
          status: 'done';
          processed: number;
          total: number;
          applied: number;
          updated: number;
      }
    | {
          status: 'pending' | 'processing';
          processed: number;
          total: number;
          applied: number;
          updated: number;
      }
    | {
          status: 'failed';
          processed: number;
          total: number;
          applied: number;
          updated: number;
      };

interface MatchesResponse {
    data: ServerTransaction[];
    total: number;
    next_offset: number | null;
}

interface ApplyResponse {
    status?: 'done';
    processed?: number;
    total?: number;
    applied?: number;
    updated?: number;
    job_id?: string;
}

type Step = 'prompt' | 'preview' | 'applying';

interface ApplyAutomationRuleFlowProps {
    rule: AutomationRule;
    /** Whether we should show the initial "do you want to apply" prompt or jump straight into preview. */
    initialStep?: Extract<Step, 'prompt' | 'preview'>;
    onBackToEdit?: () => void;
    onClose: () => void;
    onApplied?: () => void;
}

export function ApplyAutomationRuleFlow({
    rule,
    initialStep = 'prompt',
    onBackToEdit,
    onClose,
    onApplied,
}: ApplyAutomationRuleFlowProps) {
    const [step, setStep] = useState<Step>(initialStep);
    const [onlyUncategorized, setOnlyUncategorized] = useState(true);
    const [transactions, setTransactions] = useState<ServerTransaction[]>([]);
    const [total, setTotal] = useState<number | null>(null);
    const [nextOffset, setNextOffset] = useState<number | null>(null);
    const [loadingPage, setLoadingPage] = useState(false);
    const [initialLoad, setInitialLoad] = useState(false);
    const [applying, setApplying] = useState(false);
    const [progress, setProgress] = useState<ApplyResult | null>(null);
    const sentinelRef = useRef<HTMLLIElement | null>(null);
    const activeOnlyUncategorizedRef = useRef(onlyUncategorized);
    const loadingOffsetsRef = useRef<Set<number>>(new Set());
    const loadedOffsetsRef = useRef<Set<number>>(new Set());

    const fetchPage = useCallback(
        async (offset: number, replace: boolean) => {
            const requestedOnlyUncategorized = onlyUncategorized;

            if (
                !replace &&
                (loadingOffsetsRef.current.has(offset) ||
                    loadedOffsetsRef.current.has(offset))
            ) {
                return;
            }

            loadingOffsetsRef.current.add(offset);
            setLoadingPage(true);
            try {
                const url = matchesRoute(rule.id, {
                    query: {
                        offset,
                        per_page: 50,
                        only_uncategorized: requestedOnlyUncategorized ? 1 : 0,
                    },
                }).url;
                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) {
                    throw new Error('Failed to load matches');
                }
                const json = (await res.json()) as MatchesResponse;

                if (
                    activeOnlyUncategorizedRef.current !==
                    requestedOnlyUncategorized
                ) {
                    return;
                }

                loadedOffsetsRef.current.add(offset);
                setTotal(json.total);
                setNextOffset(json.next_offset);
                setTransactions((prev) =>
                    mergeUniqueTransactions(prev, json.data, replace),
                );
            } catch (error) {
                console.error(error);
                toast.error(__('Failed to load matching transactions.'));
            } finally {
                loadingOffsetsRef.current.delete(offset);
                setLoadingPage(false);
            }
        },
        [onlyUncategorized, rule.id],
    );

    // Reset list when entering preview or toggling filter
    useEffect(() => {
        if (step !== 'preview') {
            return;
        }
        activeOnlyUncategorizedRef.current = onlyUncategorized;
        loadingOffsetsRef.current.clear();
        loadedOffsetsRef.current.clear();
        setTransactions([]);
        setNextOffset(null);
        setInitialLoad(true);
        fetchPage(0, true).finally(() => setInitialLoad(false));
    }, [step, onlyUncategorized, fetchPage]);

    // Infinite scroll
    useEffect(() => {
        if (step !== 'preview' || !sentinelRef.current) {
            return;
        }
        const el = sentinelRef.current;
        const observer = new IntersectionObserver(
            (entries) => {
                if (
                    entries[0]?.isIntersecting &&
                    nextOffset !== null &&
                    !loadingPage
                ) {
                    fetchPage(nextOffset, false);
                }
            },
            { rootMargin: '200px' },
        );
        observer.observe(el);
        return () => observer.disconnect();
    }, [step, nextOffset, loadingPage, fetchPage]);

    const pollStatus = useCallback(
        async (jobId: string) => {
            const url = statusRoute(jobId).url;
            const poll = async (): Promise<void> => {
                const res = await fetch(url, {
                    headers: { Accept: 'application/json' },
                });
                if (!res.ok) {
                    throw new Error('Status check failed');
                }
                const data = (await res.json()) as ApplyResult;
                setProgress(data);
                if (data.status === 'done') {
                    toast.success(
                        __('Rule applied to :count transaction(s).', {
                            count: String(data.applied),
                        }),
                    );
                    onApplied?.();
                    onClose();
                    return;
                }
                if (data.status === 'failed') {
                    toast.error(__('Failed to apply rule to transactions.'));
                    setApplying(false);
                    return;
                }
                setTimeout(() => {
                    void poll();
                }, 1000);
            };
            await poll();
        },
        [onApplied, onClose],
    );

    const handleApply = useCallback(async () => {
        setApplying(true);
        setStep('applying');
        try {
            const url = applyRoute(rule.id).url;
            const csrf =
                (
                    document.querySelector(
                        'meta[name="csrf-token"]',
                    ) as HTMLMetaElement | null
                )?.content ?? '';
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    only_uncategorized: onlyUncategorized,
                }),
            });
            if (!res.ok && res.status !== 202) {
                throw new Error('Apply failed');
            }
            const data = (await res.json()) as ApplyResponse;

            if (data.job_id) {
                setProgress({
                    status: 'processing',
                    processed: 0,
                    total: data.total ?? total ?? 0,
                    applied: 0,
                    updated: 0,
                });
                await pollStatus(data.job_id);
                return;
            }

            toast.success(
                __('Rule applied to :count transaction(s).', {
                    count: String(data.applied ?? 0),
                }),
            );
            onApplied?.();
            onClose();
        } catch (error) {
            console.error(error);
            toast.error(__('Failed to apply rule to transactions.'));
            setApplying(false);
            setStep('preview');
        }
    }, [onApplied, onClose, onlyUncategorized, pollStatus, rule.id, total]);

    if (step === 'prompt') {
        return (
            <div className="space-y-4">
                <div className="flex items-start gap-3 rounded-md border bg-muted/30 p-4">
                    <Sparkles className="mt-0.5 h-5 w-5 text-primary" />
                    <div className="space-y-1 text-sm">
                        <p className="font-medium">
                            {__(
                                'Apply this rule to your existing transactions?',
                            )}
                        </p>
                        <p className="text-muted-foreground">
                            {__(
                                'Future transactions will be categorized automatically either way. You can preview the matches before confirming.',
                            )}
                        </p>
                    </div>
                </div>
                <div className="flex justify-end gap-2">
                    <Button variant="outline" onClick={onClose}>
                        {__('Skip for now')}
                    </Button>
                    <Button onClick={() => setStep('preview')}>
                        {__('Review matches')}
                        <ArrowRight className="ml-2 h-4 w-4" />
                    </Button>
                </div>
            </div>
        );
    }

    if (step === 'applying') {
        return (
            <div className="space-y-4 py-6 text-center">
                <Loader2 className="mx-auto h-8 w-8 animate-spin text-primary" />
                <p className="text-sm text-muted-foreground">
                    {progress
                        ? __('Applying rule… :processed of :total processed', {
                              processed: String(progress.processed),
                              total: String(progress.total),
                          })
                        : __('Applying rule…')}
                </p>
            </div>
        );
    }

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <div className="text-sm text-muted-foreground">
                    {total === null
                        ? __('Loading matches…')
                        : __(':count matching transaction(s)', {
                              count: String(total),
                          })}
                </div>
                <label className="flex items-center gap-2 text-xs">
                    <Checkbox
                        checked={onlyUncategorized}
                        onCheckedChange={(checked) =>
                            setOnlyUncategorized(checked === true)
                        }
                        disabled={applying}
                    />
                    <span>{__('Only apply to uncategorized')}</span>
                </label>
            </div>

            <div className="max-h-80 overflow-y-auto rounded-md border">
                {initialLoad ? (
                    <div className="flex items-center justify-center p-8">
                        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                    </div>
                ) : transactions.length === 0 ? (
                    <div className="p-6 text-center text-sm text-muted-foreground">
                        {__(
                            'No matching transactions. Future transactions will still be categorized automatically.',
                        )}
                    </div>
                ) : (
                    <ul className="divide-y">
                        {transactions.map((tx) => (
                            <PreviewRow
                                key={tx.id}
                                transaction={tx}
                                rule={rule}
                            />
                        ))}
                        <li ref={sentinelRef} className="h-1" />
                        {loadingPage && nextOffset !== null && (
                            <li className="flex items-center justify-center p-3">
                                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                            </li>
                        )}
                    </ul>
                )}
            </div>

            <div className="flex flex-wrap items-center justify-end gap-2">
                {onBackToEdit && (
                    <Button
                        variant="ghost"
                        onClick={onBackToEdit}
                        disabled={applying}
                    >
                        <ArrowLeft className="mr-2 h-4 w-4" />
                        {__('Back to edit')}
                    </Button>
                )}
                <Button variant="outline" onClick={onClose} disabled={applying}>
                    {__('Cancel')}
                </Button>
                <Button
                    onClick={handleApply}
                    disabled={applying || total === 0 || total === null}
                >
                    {total !== null && total > 0
                        ? __('Apply to :count transaction(s)', {
                              count: String(total),
                          })
                        : __('Apply')}
                </Button>
            </div>
        </div>
    );
}

export function mergeUniqueTransactions(
    previous: ServerTransaction[],
    incoming: ServerTransaction[],
    replace: boolean,
): ServerTransaction[] {
    const seen = new Set<string>();
    const transactions = replace ? incoming : [...previous, ...incoming];

    return transactions.filter((transaction) => {
        if (seen.has(transaction.id)) {
            return false;
        }

        seen.add(transaction.id);

        return true;
    });
}

function PreviewRow({
    transaction,
    rule,
}: {
    transaction: ServerTransaction;
    rule: AutomationRule;
}) {
    const date = transaction.transaction_date
        ? format(parseISO(transaction.transaction_date), 'MMM d, yyyy')
        : '';
    const currentCategory = transaction.category?.name ?? __('Uncategorized');
    const newCategory = rule.category?.name ?? null;
    const newLabels = rule.labels ?? [];
    const existingLabelIds = new Set(
        transaction.labels?.map((l) => l.id) ?? [],
    );
    const labelsToAdd = newLabels.filter((l) => !existingLabelIds.has(l.id));

    return (
        <li className="flex flex-col gap-1 px-3 py-2 text-sm">
            <div className="flex items-center justify-between gap-3">
                <span className="line-clamp-1 font-medium">
                    {transaction.description}
                </span>
                <span className="shrink-0 tabular-nums">
                    {formatCurrency(
                        transaction.amount,
                        transaction.currency_code,
                    )}
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span>{date}</span>
                {transaction.account?.name && (
                    <span>· {transaction.account.name}</span>
                )}
                <span className="ml-auto inline-flex flex-wrap items-center gap-1">
                    <Badge variant="outline">{currentCategory}</Badge>
                    {newCategory && newCategory !== currentCategory && (
                        <>
                            <ArrowRight className="h-3 w-3" />
                            <Badge>{newCategory}</Badge>
                        </>
                    )}
                    {labelsToAdd.map((label) => (
                        <Badge
                            key={label.id}
                            variant="secondary"
                            style={{
                                backgroundColor: label.color
                                    ? `${label.color}33`
                                    : undefined,
                                color: label.color ?? undefined,
                            }}
                        >
                            +{label.name}
                        </Badge>
                    ))}
                </span>
            </div>
        </li>
    );
}
