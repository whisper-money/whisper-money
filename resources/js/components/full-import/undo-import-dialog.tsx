import { destroy } from '@/actions/App/Http/Controllers/Settings/FullImportController';
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
import { formatCount, sourceLabel } from '@/lib/full-import-format';
import { type ImportHistoryEntry } from '@/types/full-import';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface UndoImportDialogProps {
    entry: ImportHistoryEntry;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    locale: string;
}

/** The confirmation in front of undoing an import, listing what goes. */
export function UndoImportDialog({
    entry,
    open,
    onOpenChange,
    locale,
}: UndoImportDialogProps) {
    const [isUndoing, setIsUndoing] = useState(false);
    const summary = entry.summary;

    const handleUndo = () => {
        setIsUndoing(true);

        router.delete(destroy.url(entry.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
            onFinish: () => setIsUndoing(false),
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
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
                            {summary && (
                                <ul className="flex list-disc flex-col gap-1 pl-5 text-foreground">
                                    {summary.accounts.length > 0 && (
                                        <li>
                                            {__(':count accounts: :names', {
                                                count: summary.accounts.length,
                                                names: summary.accounts
                                                    .map(
                                                        (account) =>
                                                            account.name,
                                                    )
                                                    .join(', '),
                                            })}
                                        </li>
                                    )}
                                    {summary.categories > 0 && (
                                        <li>
                                            {__(':count new categories', {
                                                count: summary.categories,
                                            })}
                                        </li>
                                    )}
                                    <li>
                                        {__(
                                            ':transactions transactions and :balances daily balances',
                                            {
                                                transactions: formatCount(
                                                    summary.transactions,
                                                    locale,
                                                ),
                                                balances: formatCount(
                                                    summary.balances,
                                                    locale,
                                                ),
                                            },
                                        )}
                                    </li>
                                </ul>
                            )}
                            {summary?.into_own_accounts.map((account) => (
                                <span key={account.name}>
                                    {__(
                                        ':name stays, without the :count imported transactions.',
                                        {
                                            name: account.name,
                                            count: formatCount(
                                                account.transactions,
                                                locale,
                                            ),
                                        },
                                    )}
                                </span>
                            ))}
                            {(summary?.accounts.length ?? 0) > 0 && (
                                <span>
                                    {__(
                                        'Anything you added to the new accounts afterwards is deleted too.',
                                    )}
                                </span>
                            )}
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
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isUndoing}>
                        {__('Cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={handleUndo}
                        disabled={isUndoing}
                        variant="destructive"
                    >
                        {isUndoing ? __('Undoing…') : __('Undo import')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
