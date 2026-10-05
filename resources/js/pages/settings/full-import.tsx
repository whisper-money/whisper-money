import {
    create,
    index as importIndex,
} from '@/actions/App/Http/Controllers/Settings/FullImportController';
import { Notice, Pill } from '@/components/full-import/full-import-layout';
import { UndoImportDialog } from '@/components/full-import/undo-import-dialog';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useLocale } from '@/hooks/use-locale';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    aiOutcomeNote,
    categoryCount,
    countLabel,
    sourceLabel,
    transactionCount,
} from '@/lib/full-import-format';
import { type BreadcrumbItem } from '@/types';
import { type ImportHistoryEntry } from '@/types/full-import';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage, usePoll } from '@inertiajs/react';
import { Check, Clock, FileSpreadsheet, Undo2, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';

interface FullImportPageProps {
    canStart: boolean;
    windowEndsAt: string | null;
    imports: ImportHistoryEntry[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Import from another app', href: importIndex().url },
];

const WHAT_GETS_IMPORTED: { title: string; description: string }[] = [
    {
        title: 'Accounts',
        description: 'With their bank, type, currency and IBAN',
    },
    {
        title: 'Categories and subcategories',
        description: 'Merged with yours when they already exist',
    },
    {
        title: 'Transactions',
        description: 'With their notes and no duplicates',
    },
    { title: 'Daily balances', description: 'When the file has them' },
];

const DAY_MS = 24 * 60 * 60 * 1000;

function statusPill(entry: ImportHistoryEntry) {
    if (entry.undone_at) {
        return <Pill>{__('Undone')}</Pill>;
    }

    if (entry.status === 'undoing') {
        return (
            <Pill>
                <Spinner className="size-3" />
                {__('Undoing…')}
            </Pill>
        );
    }

    if (entry.status === 'queued' || entry.status === 'processing') {
        return <Pill tone="info">{__('In progress')}</Pill>;
    }

    return entry.status === 'failed' ? (
        <Pill tone="danger">{__('Stopped before finishing')}</Pill>
    ) : null;
}

function HistoryRow({
    entry,
    locale,
}: {
    entry: ImportHistoryEntry;
    locale: string;
}) {
    const [confirming, setConfirming] = useState(false);
    const stats = entry.stats;
    // Only what is still news here: a pass running, or one that broke off.
    const aiNote =
        !entry.undone_at &&
        ['queued', 'running', 'failed'].includes(stats.ai?.status ?? '')
            ? aiOutcomeNote(stats, locale)
            : null;

    return (
        <div className="flex flex-wrap items-center gap-3.5 px-4 py-4 sm:px-5">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted">
                <FileSpreadsheet className="size-4.5" />
            </span>
            <div className="min-w-0 flex-1 basis-60">
                <div className="flex flex-wrap items-center gap-2 font-medium">
                    {[sourceLabel(entry.source), entry.file_name]
                        .filter(Boolean)
                        .join(' · ')}
                    {statusPill(entry)}
                </div>
                <div className="text-[13px] text-muted-foreground">
                    {[
                        entry.created_at &&
                            formatDateMedium(
                                entry.created_at.slice(0, 10),
                                locale,
                            ),
                        countLabel(
                            stats.accounts?.created ?? 0,
                            __('1 new account'),
                            __(':count new accounts', {
                                count: stats.accounts?.created ?? 0,
                            }),
                        ),
                        categoryCount(stats.categories?.created ?? 0, locale),
                        transactionCount(
                            stats.transactions?.imported ?? 0,
                            locale,
                        ),
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </div>
                {aiNote && (
                    <div className="text-[13px] text-muted-foreground">
                        {aiNote}
                    </div>
                )}
            </div>
            {entry.undoable && (
                <>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setConfirming(true)}
                    >
                        <Undo2 className="size-4" />
                        {__('Undo')}
                    </Button>
                    <UndoImportDialog
                        entry={entry}
                        open={confirming}
                        onOpenChange={setConfirming}
                        locale={locale}
                    />
                </>
            )}
        </div>
    );
}

export default function FullImport({
    canStart,
    windowEndsAt,
    imports,
}: FullImportPageProps) {
    const locale = useLocale();
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    // An import being written or being undone both finish on the queue, so
    // the list keeps asking until neither is left.
    const running = imports.some((entry) =>
        ['queued', 'processing', 'undoing'].includes(entry.status),
    );
    const { start, stop } = usePoll(
        3000,
        { only: ['imports'] },
        { autoStart: false },
    );

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }
    }, [running, start, stop]);

    // Read once: the chip counts whole days, so a render later in the same
    // visit has nothing new to say.
    const [now] = useState(() => Date.now());
    const windowEnd = windowEndsAt
        ? formatDateMedium(windowEndsAt.slice(0, 10), locale)
        : '';
    const daysLeft = windowEndsAt
        ? Math.max(
              0,
              Math.ceil((new Date(windowEndsAt).getTime() - now) / DAY_MS),
          )
        : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Import from another app')} />

            <SettingsLayout>
                <div className="flex flex-col gap-8">
                    <HeadingSmall
                        title={__('Import from another app')}
                        description={__(
                            'Bring your accounts, categories and transactions from Banktrack or another app with a CSV or Excel file, and pick up where you left off.',
                        )}
                    />

                    {errors?.import && (
                        <Notice tone="danger">{errors.import}</Notice>
                    )}

                    <div className="flex flex-col gap-5 rounded-xl border p-5 sm:p-6">
                        {daysLeft !== null && windowEndsAt && canStart && (
                            <span className="inline-flex w-fit items-center gap-2 rounded-full bg-muted px-3 py-1.5 text-[13px] font-medium">
                                <Clock className="size-3.5" />
                                {countLabel(
                                    daysLeft,
                                    __('1 day left, until :date', {
                                        date: windowEnd,
                                    }),
                                    __(':count days left, until :date', {
                                        count: daysLeft,
                                        date: windowEnd,
                                    }),
                                )}
                            </span>
                        )}

                        <div className="grid gap-x-6 gap-y-3.5 sm:grid-cols-2">
                            {WHAT_GETS_IMPORTED.map((item) => (
                                <div key={item.title} className="flex gap-2.5">
                                    <Check className="mt-0.5 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                    <div>
                                        <div className="font-medium">
                                            {__(item.title)}
                                        </div>
                                        <div className="text-[13px] text-muted-foreground">
                                            {__(item.description)}
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>

                        <div className="flex flex-wrap items-center gap-4 border-t pt-4">
                            {canStart ? (
                                <>
                                    <Button asChild>
                                        <Link href={create()}>
                                            <Upload className="size-4" />
                                            {__('Start import')}
                                        </Link>
                                    </Button>
                                    <span className="text-[13px] text-muted-foreground">
                                        {__(
                                            'Banktrack, or any CSV, Excel or Numbers file',
                                        )}
                                    </span>
                                </>
                            ) : (
                                <span className="text-[13px] text-muted-foreground">
                                    {__(
                                        'Importing from another app is open during your first days. You can still undo an import from here.',
                                    )}
                                </span>
                            )}
                        </div>
                    </div>

                    {imports.length > 0 && (
                        <div className="flex flex-col gap-3">
                            <h3 className="text-base font-medium">
                                {__('Imports')}
                            </h3>
                            <div className="flex flex-col divide-y rounded-xl border">
                                {imports.map((entry) => (
                                    <HistoryRow
                                        key={entry.id}
                                        entry={entry}
                                        locale={locale}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
