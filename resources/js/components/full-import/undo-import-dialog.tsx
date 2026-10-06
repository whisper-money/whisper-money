import { destroy } from '@/actions/App/Http/Controllers/Settings/FullImportController';
import { Notice } from '@/components/full-import/full-import-layout';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    countLabel,
    dailyBalanceCount,
    sourceLabel,
    transactionCount,
} from '@/lib/full-import-format';
import { trackImport } from '@/lib/full-import-telemetry';
import { type ImportHistoryEntry } from '@/types/full-import';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState, type MouseEvent } from 'react';

interface UndoImportDialogProps {
    entry: ImportHistoryEntry;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    locale: string;
}

/** What undoing takes out, one line per kind of thing. */
function SummaryList({
    entry,
    locale,
}: {
    entry: ImportHistoryEntry;
    locale: string;
}) {
    const summary = entry.summary;

    if (!summary) {
        return null;
    }

    return (
        <>
            <ul className="flex list-disc flex-col gap-1 pl-5 text-foreground">
                {summary.accounts.length > 0 && (
                    <li>
                        {countLabel(
                            summary.accounts.length,
                            __('1 account: :names', {
                                names: summary.accounts[0]?.name ?? '',
                            }),
                            __(':count accounts: :names', {
                                count: summary.accounts.length,
                                names: summary.accounts
                                    .map((account) => account.name)
                                    .join(', '),
                            }),
                        )}
                    </li>
                )}
                {summary.categories > 0 && (
                    <li>
                        {countLabel(
                            summary.categories,
                            __('1 new category'),
                            __(':count new categories', {
                                count: summary.categories,
                            }),
                        )}
                    </li>
                )}
                <li>
                    {__(':transactions and :balances', {
                        transactions: transactionCount(
                            summary.transactions,
                            locale,
                        ),
                        balances: dailyBalanceCount(summary.balances, locale),
                    })}
                </li>
            </ul>
            {summary.into_own_accounts.map((account) => (
                <span key={account.name}>
                    {countLabel(
                        account.transactions,
                        __(':name stays, without the imported transaction.', {
                            name: account.name,
                        }),
                        __(
                            ':name stays, without the :count imported transactions.',
                            {
                                name: account.name,
                                count: account.transactions,
                            },
                        ),
                    )}
                </span>
            ))}
            {(summary.connected_accounts ?? []).map((account) => (
                <span key={account.name}>
                    {__(
                        ':name is now connected to your bank, so it stays; only the imported transactions are removed.',
                        { name: account.name },
                    )}
                </span>
            ))}
            {summary.later_transactions > 0 && (
                <span>
                    {countLabel(
                        summary.later_transactions,
                        __(
                            '1 transaction added to these accounts afterwards (by hand or by another import) is deleted too.',
                        ),
                        __(
                            ':count transactions added to these accounts afterwards (by hand or by another import) are deleted too.',
                            { count: summary.later_transactions },
                        ),
                    )}
                </span>
            )}
            {summary.categories > 0 && (
                <span>
                    {__(
                        'Subcategories you created under the imported categories are removed too.',
                    )}
                </span>
            )}
        </>
    );
}

/**
 * The confirmation in front of undoing an import, listing what goes. It stays
 * open while the undo runs and shows a failure inside itself, so the user is
 * never left guessing whether anything happened.
 */
export function UndoImportDialog({
    entry,
    open,
    onOpenChange,
    locale,
}: UndoImportDialogProps) {
    const [isUndoing, setIsUndoing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const handleUndo = (event: MouseEvent) => {
        // The action button closes the dialog by default; it has to stay
        // up until the server answers.
        event.preventDefault();
        setIsUndoing(true);
        setError(null);

        router.delete(destroy.url(entry.id), {
            preserveScroll: true,
            // Counted once the server has taken the undo on, not on the click:
            // a refused undo was never requested as far as the funnel goes.
            onSuccess: () => {
                trackImport('full_import_undo_requested', {
                    transactions: entry.stats.transactions?.imported ?? 0,
                });
                onOpenChange(false);
            },
            onError: (errors) =>
                setError(
                    errors.import ??
                        __('The import could not be undone. Try again.'),
                ),
            onFinish: () => setIsUndoing(false),
        });
    };

    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                if (!isUndoing) {
                    setError(null);
                    onOpenChange(next);
                }
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {__('Undo the :source import?', {
                            source: sourceLabel(entry.source),
                        })}
                    </AlertDialogTitle>
                    <AlertDialogDescription asChild>
                        <div className="flex flex-col gap-3 text-sm text-muted-foreground">
                            <span>
                                {__(
                                    'We delete everything it created on :date:',
                                    {
                                        date: entry.created_at
                                            ? formatDateMedium(
                                                  entry.created_at.slice(0, 10),
                                                  locale,
                                              )
                                            : '',
                                    },
                                )}
                            </span>
                            <SummaryList entry={entry} locale={locale} />
                            {entry.mode === 'wipe' && (
                                <span>
                                    {__(
                                        'Undoing does not bring back what was deleted when the import started from scratch.',
                                    )}
                                </span>
                            )}
                        </div>
                    </AlertDialogDescription>
                </AlertDialogHeader>
                {error && <Notice tone="danger">{error}</Notice>}
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isUndoing}>
                        {__('Cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={handleUndo}
                        disabled={isUndoing}
                        variant="destructive"
                    >
                        {isUndoing && <Spinner className="size-4" />}
                        {isUndoing ? __('Undoing…') : __('Undo import')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
