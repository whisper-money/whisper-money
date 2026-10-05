import { WizardScreen } from '@/components/full-import/full-import-layout';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { formatCount } from '@/lib/full-import-format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    type ImportStage,
    type ImportStats,
    type ImportStatus,
} from '@/types/full-import';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { Check, Circle } from 'lucide-react';

type RowStage = Exclude<ImportStage, 'upload' | 'queued' | 'done'>;

const STAGES: RowStage[] = [
    'wipe',
    'accounts',
    'categories',
    'transactions',
    'balances',
    'ai',
];

const STAGE_LABELS: Record<RowStage, string> = {
    wipe: 'Deleting your manual accounts',
    accounts: 'Accounts',
    categories: 'Categories',
    transactions: 'Transactions',
    balances: 'Daily balances',
    ai: 'Categorize with AI',
};

export interface UploadProgress {
    sent: number;
    total: number;
}

/** How far along the whole import is, 0 to 100, uploads included. */
export function overallProgress(
    status: ImportStatus | null,
    upload: UploadProgress | null,
): number {
    if (!status || status.status === 'draft') {
        return upload && upload.total > 0
            ? Math.round((upload.sent / upload.total) * 15)
            : 0;
    }

    if (status.status === 'completed' || status.status === 'failed') {
        return 100;
    }

    const stats = status.stats;
    const transactions = stats.transactions;

    switch (stats.stage) {
        case 'wipe':
        case 'accounts':
            return 18;
        case 'categories':
            return 22;
        case 'transactions':
            return (
                25 +
                Math.round(
                    ((transactions?.processed ?? 0) /
                        Math.max(1, transactions?.total ?? 1)) *
                        65,
                )
            );
        case 'balances':
            return 92;
        case 'ai':
            return 97;
        default:
            return 15;
    }
}

function stageMeta(
    stage: RowStage,
    stats: ImportStats,
    locale: string,
): string | null {
    switch (stage) {
        case 'wipe':
            return stats.wiped
                ? __(':count accounts deleted', { count: stats.wiped.accounts })
                : null;
        case 'accounts':
            return stats.accounts
                ? __(':count created', { count: stats.accounts.created })
                : null;
        case 'categories':
            return stats.categories
                ? __(':count created', { count: stats.categories.created })
                : null;
        case 'transactions':
            return stats.transactions
                ? __(':processed of :total', {
                      processed: formatCount(
                          stats.transactions.processed,
                          locale,
                      ),
                      total: formatCount(stats.transactions.total, locale),
                  })
                : null;
        case 'balances':
            return stats.balances
                ? formatCount(stats.balances.imported, locale)
                : null;
        default:
            return stats.uncategorized !== undefined
                ? __(':count without a category', {
                      count: formatCount(stats.uncategorized, locale),
                  })
                : null;
    }
}

export function StepProgress({
    status,
    upload,
    showDashboardLink,
    locale,
}: {
    status: ImportStatus | null;
    upload: UploadProgress | null;
    showDashboardLink: boolean;
    locale: string;
}) {
    const percent = overallProgress(status, upload);
    const stats = status?.stats ?? {};
    const stages = STAGES.filter(
        (stage) => stage !== 'wipe' || status?.mode === 'wipe',
    );
    const currentIndex = stats.stage
        ? stages.indexOf(stats.stage as RowStage)
        : -1;
    const finished = status?.status === 'completed';

    return (
        <WizardScreen
            title={__('Importing your data')}
            description={
                status && status.status !== 'draft'
                    ? __(
                          'It takes a couple of minutes. You can close this tab: we keep going in the background.',
                      )
                    : __(
                          'Sending your rows. Keep this tab open until they are all up.',
                      )
            }
        >
            <div className="flex flex-col gap-2">
                <div className="flex justify-between text-sm">
                    <span className="font-medium">{__('Progress')}</span>
                    <span className="tabular-nums">{percent} %</span>
                </div>
                <Progress
                    value={percent}
                    className="h-2"
                    aria-label={__('Import progress')}
                />
                {upload && (!status || status.status === 'draft') && (
                    <span className="text-[13px] text-muted-foreground">
                        {__('Uploading :sent of :total rows', {
                            sent: formatCount(upload.sent, locale),
                            total: formatCount(upload.total, locale),
                        })}
                    </span>
                )}
            </div>

            <ol className="flex flex-col divide-y rounded-xl border bg-card">
                {stages.map((stage, index) => {
                    const done =
                        finished ||
                        (currentIndex !== -1 && index < currentIndex);
                    const active = !finished && index === currentIndex;

                    return (
                        <li
                            key={stage}
                            className="flex items-center gap-3 px-4 py-3 sm:px-5"
                        >
                            {done ? (
                                <Check className="size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                            ) : active ? (
                                <Spinner className="size-4 shrink-0" />
                            ) : (
                                <Circle className="size-4 shrink-0 text-muted-foreground/50" />
                            )}
                            <span
                                className={cn(
                                    'flex-1',
                                    !done && !active && 'text-muted-foreground',
                                )}
                            >
                                {__(STAGE_LABELS[stage])}
                            </span>
                            <span className="text-[13px] text-muted-foreground tabular-nums">
                                {stageMeta(stage, stats, locale)}
                            </span>
                        </li>
                    );
                })}
            </ol>

            {showDashboardLink && status && status.status !== 'draft' && (
                <Link
                    href={dashboard()}
                    className="w-fit text-sm font-medium underline underline-offset-4"
                >
                    {__('Go to the dashboard')}
                </Link>
            )}
        </WizardScreen>
    );
}
