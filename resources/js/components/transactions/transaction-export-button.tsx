import { count as countTransactions } from '@/actions/App/Http/Controllers/Api/TransactionController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/hooks/use-locale';
import {
    downloadTransactionsCsv,
    type TransactionExportParams,
} from '@/lib/transaction-csv-export';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Download } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

type ExportScope = 'loaded' | 'all';

interface TransactionExportButtonProps {
    params: TransactionExportParams;
    /** Ids of the rows the list holds right now, in its order. */
    loadedIds: string[];
    /** Whether the list has more pages it has not loaded yet. */
    hasMore: boolean;
    /** One line per active search or filter, for the dialog's summary. */
    filterChips: string[];
}

interface ExportProgress {
    received: number;
    /** `null` when the count could not be had, so there is no end to show. */
    total: number | null;
}

/**
 * Downloads the transactions as CSV. A list that holds every match exports
 * straight away; one with more pages to load asks first whether to export
 * the rows on screen or everything the search and filters select.
 */
export function TransactionExportButton({
    params,
    loadedIds,
    hasMore,
    filterChips,
}: TransactionExportButtonProps) {
    const [dialogOpen, setDialogOpen] = useState(false);
    // A fresh key per opening starts the dialog over: default option, count
    // loading again, rather than one frame of the last visit's numbers.
    const [dialogKey, setDialogKey] = useState(0);
    const { start, cancel, progress } = useCsvDownload();
    const isDirectDownloading = progress !== null && !dialogOpen;

    async function handleClick() {
        if (hasMore) {
            setDialogKey((key) => key + 1);
            setDialogOpen(true);
            return;
        }

        await start({ params, total: loadedIds.length });
    }

    return (
        <>
            <Button
                variant="outline"
                className="flex gap-2 max-sm:size-9 max-sm:px-0"
                onClick={handleClick}
                disabled={
                    isDirectDownloading || (!hasMore && loadedIds.length === 0)
                }
                aria-label={__('Export CSV')}
            >
                {isDirectDownloading ? (
                    <Spinner className="size-4" />
                ) : (
                    <Download className="size-4" />
                )}
                <span className="hidden sm:inline">{__('Export')}</span>
            </Button>

            {hasMore ? (
                <TransactionExportDialog
                    key={dialogKey}
                    open={dialogOpen}
                    onOpenChange={(open) => {
                        if (!open) {
                            cancel();
                        }
                        setDialogOpen(open);
                    }}
                    params={params}
                    loadedIds={loadedIds}
                    chips={filterChips}
                    progress={progress}
                    onExport={async (ids, total) => {
                        if (await start({ params, ids, total })) {
                            setDialogOpen(false);
                        }
                    }}
                />
            ) : null}
        </>
    );
}

interface TransactionExportDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    params: TransactionExportParams;
    loadedIds: string[];
    chips: string[];
    progress: ExportProgress | null;
    onExport: (ids: string[] | undefined, total: number | null) => void;
}

function TransactionExportDialog({
    open,
    onOpenChange,
    params,
    loadedIds,
    chips,
    progress,
    onExport,
}: TransactionExportDialogProps) {
    const [scope, setScope] = useState<ExportScope>('all');
    const matchingCount = useMatchingCount(open, params);
    const formatCount = useCountFormatter();

    const selectedCount = scope === 'all' ? matchingCount : loadedIds.length;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-sm:top-auto max-sm:bottom-0 max-sm:max-w-full max-sm:translate-y-0 max-sm:rounded-b-none"
                data-testid="transaction-export-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{__('Export transactions')}</DialogTitle>
                    <DialogDescription>
                        {progress
                            ? progressText(progress, formatCount)
                            : __(
                                  'The CSV uses the search and filters applied right now.',
                              )}
                    </DialogDescription>
                </DialogHeader>

                {progress ? (
                    <Progress
                        value={progressPercent(progress)}
                        className={cn(
                            'h-2',
                            progress.total === null && 'animate-pulse',
                        )}
                        aria-label={__('Export progress')}
                    />
                ) : (
                    <>
                        {chips.length > 0 ? (
                            <ul
                                className="flex flex-wrap gap-1.5"
                                aria-label={__('Active filters')}
                            >
                                {chips.map((chip) => (
                                    <li
                                        key={chip}
                                        className="rounded-full border border-border bg-muted px-2.5 py-0.5 text-xs font-medium text-foreground"
                                    >
                                        {chip}
                                    </li>
                                ))}
                            </ul>
                        ) : null}

                        <RadioGroup
                            value={scope}
                            onValueChange={(value) =>
                                setScope(value as ExportScope)
                            }
                            className="gap-2"
                        >
                            <ScopeOption
                                value="loaded"
                                title={__('Only loaded')}
                                description={__(
                                    ':count transactions you see in the list now',
                                    { count: formatCount(loadedIds.length) },
                                )}
                            />
                            <ScopeOption
                                value="all"
                                title={__('All matching')}
                                description={
                                    matchingCount === undefined ? (
                                        <Skeleton
                                            className="inline-block h-4 w-48 align-middle"
                                            data-testid="matching-count-skeleton"
                                        />
                                    ) : matchingCount === null ? (
                                        __(
                                            'Every transaction with this search and filters',
                                        )
                                    ) : (
                                        __(
                                            ':count transactions with this search and filters',
                                            {
                                                count: formatCount(
                                                    matchingCount,
                                                ),
                                            },
                                        )
                                    )
                                }
                            />
                        </RadioGroup>
                    </>
                )}

                <DialogFooter>
                    <Button
                        variant="outline"
                        className="max-sm:h-11"
                        onClick={() => onOpenChange(false)}
                    >
                        {__('Cancel')}
                    </Button>
                    {progress ? null : (
                        <Button
                            className="max-sm:h-11"
                            disabled={selectedCount === undefined}
                            onClick={() =>
                                onExport(
                                    scope === 'loaded' ? loadedIds : undefined,
                                    selectedCount ?? null,
                                )
                            }
                        >
                            <Download className="size-4" />
                            {typeof selectedCount === 'number'
                                ? __('Download :count transactions', {
                                      count: formatCount(selectedCount),
                                  })
                                : __('Download')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ScopeOption({
    value,
    title,
    description,
}: {
    value: ExportScope;
    title: string;
    description: React.ReactNode;
}) {
    const id = `transaction-export-scope-${value}`;

    return (
        <label
            htmlFor={id}
            className="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors hover:bg-muted/50 has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5 dark:has-[[data-state=checked]]:bg-primary/10"
        >
            <RadioGroupItem value={value} id={id} className="mt-0.5" />
            <span className="flex flex-col gap-0.5">
                <span className="text-sm font-medium">{title}</span>
                <span className="text-sm text-muted-foreground">
                    {description}
                </span>
            </span>
        </label>
    );
}

function progressText(
    { received, total }: ExportProgress,
    formatCount: (value: number) => string,
): string {
    if (total === null) {
        return __('Preparing :done…', { done: formatCount(received) });
    }

    return __('Preparing :done of :total…', {
        done: formatCount(Math.min(received, total)),
        total: formatCount(total),
    });
}

/** Full and pulsing when there is no total to measure against. */
function progressPercent({ received, total }: ExportProgress): number {
    if (total === null) {
        return 100;
    }

    return total > 0 ? (Math.min(received, total) / total) * 100 : 0;
}

function useCountFormatter(): (value: number) => string {
    const locale = useLocale();

    return useCallback(
        (value: number) => new Intl.NumberFormat(locale).format(value),
        [locale],
    );
}

/**
 * How many transactions the filters select, fetched each time the dialog
 * opens: `undefined` while loading, `null` when the count could not be had.
 */
function useMatchingCount(
    open: boolean,
    params: TransactionExportParams,
): number | null | undefined {
    const [matchingCount, setMatchingCount] = useState<number | null>();
    const query = JSON.stringify(params);

    useEffect(() => {
        if (!open) {
            return;
        }

        const controller = new AbortController();
        setMatchingCount(undefined);

        fetch(countTransactions.url({ query: JSON.parse(query) }), {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Request failed');
                }

                return response.json() as Promise<{ count: number }>;
            })
            .then(({ count }) => setMatchingCount(count))
            .catch(() => {
                if (!controller.signal.aborted) {
                    setMatchingCount(null);
                }
            });

        return () => controller.abort();
    }, [open, query]);

    return matchingCount;
}

interface StartOptions {
    params: TransactionExportParams;
    ids?: string[];
    /** How many rows to expect, for the progress bar. */
    total: number | null;
}

/**
 * Runs one CSV download at a time, keeps its progress, and reports the
 * outcome in a toast. Resolves to whether the file was saved.
 */
function useCsvDownload() {
    const formatCount = useCountFormatter();
    const [progress, setProgress] = useState<ExportProgress | null>(null);
    const controllerRef = useRef<AbortController | null>(null);

    const cancel = useCallback(() => {
        controllerRef.current?.abort();
    }, []);

    useEffect(() => cancel, [cancel]);

    const start = useCallback(
        async ({ params, ids, total }: StartOptions): Promise<boolean> => {
            const controller = new AbortController();
            controllerRef.current = controller;
            setProgress({ received: 0, total });

            try {
                const { filename, rows } = await downloadTransactionsCsv({
                    params,
                    ids,
                    signal: controller.signal,
                    onProgress: (received) => setProgress({ received, total }),
                });

                toast.success(__('CSV downloaded'), {
                    description: __(':count transactions · :file', {
                        count: formatCount(rows),
                        file: filename,
                    }),
                });

                return true;
            } catch {
                if (!controller.signal.aborted) {
                    toast.error(
                        __(
                            'Could not export the transactions. Please try again.',
                        ),
                    );
                }

                return false;
            } finally {
                controllerRef.current = null;
                setProgress(null);
            }
        },
        [formatCount],
    );

    return { start, cancel, progress };
}
