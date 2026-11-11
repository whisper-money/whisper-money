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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useLocale } from '@/hooks/use-locale';
import {
    type UserWidgetDefinition,
    type UserWidgetRevision,
} from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { useState } from 'react';
import { dashboardActions } from './dashboard-actions';
import { widgetOriginLabel } from './labels';
import { type ShownWidgetError } from './use-widget-error-reporter';

function formatWhen(iso: string | null, locale: string): string | null {
    if (iso === null) {
        return null;
    }

    return new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(iso));
}

interface Props {
    definition: UserWidgetDefinition;
    error: ShownWidgetError | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Going back to a version needs the paid plan and an account of one's own. */
    canRevert: boolean;
}

/**
 * A user widget's details, from its cell's menu: the last error its current
 * version raised, for the user to hand to their agent, and the versions it
 * keeps, each of which it can go back to.
 */
export function UserWidgetDetailsDialog({
    definition,
    error,
    open,
    onOpenChange,
    canRevert,
}: Props) {
    const locale = useLocale();
    const [reverting, setReverting] = useState<UserWidgetRevision | null>(null);
    const [isReverting, setIsReverting] = useState(false);

    return (
        <>
            <Dialog open={open} onOpenChange={onOpenChange}>
                <DialogContent
                    className="flex max-h-[85vh] flex-col sm:max-w-lg"
                    data-slot="user-widget-details"
                >
                    <DialogHeader>
                        <DialogTitle>{definition.name}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'A widget written by your agent. It is on version :number.',
                                { number: definition.revision },
                            )}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="flex min-h-0 flex-col gap-6 overflow-y-auto">
                        <section className="flex flex-col gap-2">
                            <h3 className="text-sm font-medium">
                                {__('Last error')}
                            </h3>
                            {error === null ? (
                                <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                    <CircleCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
                                    {__(
                                        'This version has not raised any error.',
                                    )}
                                </p>
                            ) : (
                                <div
                                    role="alert"
                                    data-slot="user-widget-last-error"
                                    className="flex flex-col gap-2 rounded-lg border border-destructive/40 bg-destructive/5 p-3 dark:border-red-400/40 dark:bg-red-400/10"
                                >
                                    <p className="flex items-center gap-2 text-sm font-medium">
                                        <TriangleAlert className="size-4 shrink-0 text-destructive dark:text-red-400" />
                                        {error.phase === 'load'
                                            ? __('It did not load')
                                            : __('It stopped while drawing')}
                                    </p>
                                    <p className="font-mono text-xs break-words whitespace-pre-wrap">
                                        {error.message}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {[
                                            __('Version :number', {
                                                number: error.revision,
                                            }),
                                            formatWhen(error.at, locale),
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {__(
                                            'Ask your agent to fix it: it can read this error from Whisper Money, so you do not need to copy it.',
                                        )}
                                    </p>
                                </div>
                            )}
                        </section>

                        <section className="flex flex-col gap-2">
                            <h3 className="text-sm font-medium">
                                {__('Versions')}
                            </h3>
                            <ul className="flex flex-col gap-2">
                                {definition.revisions.map((revision) => (
                                    <li
                                        key={revision.number}
                                        data-testid="user-widget-revision"
                                        className="flex items-center gap-3 rounded-lg border bg-card px-3 py-2"
                                    >
                                        <div className="flex min-w-0 flex-1 flex-col">
                                            <span className="flex items-center gap-2 text-sm font-medium">
                                                {__('Version :number', {
                                                    number: revision.number,
                                                })}
                                                {revision.number ===
                                                    definition.revision && (
                                                    <Badge variant="secondary">
                                                        {__('Current')}
                                                    </Badge>
                                                )}
                                            </span>
                                            <span className="truncate text-xs text-muted-foreground">
                                                {[
                                                    revision.restored_from ===
                                                    null
                                                        ? widgetOriginLabel(
                                                              revision.created_via,
                                                          )
                                                        : __(
                                                              'Back to version :number',
                                                              {
                                                                  number: revision.restored_from,
                                                              },
                                                          ),
                                                    formatWhen(
                                                        revision.created_at,
                                                        locale,
                                                    ),
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </span>
                                        </div>
                                        {canRevert &&
                                            revision.number !==
                                                definition.revision && (
                                                <Button
                                                    variant="outline"
                                                    size="sm"
                                                    onClick={() =>
                                                        setReverting(revision)
                                                    }
                                                >
                                                    {__('Go back to this one')}
                                                </Button>
                                            )}
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </div>
                </DialogContent>
            </Dialog>

            <AlertDialog
                open={reverting !== null}
                onOpenChange={(isOpen) => !isOpen && setReverting(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Go back to version :number?', {
                                number: reverting?.number ?? '',
                            })}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {__(
                                'The widget runs that version again, saved as a new one. The versions after it are kept, so you can come back.',
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isReverting}>
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            disabled={isReverting}
                            onClick={(event) => {
                                event.preventDefault();
                                if (reverting === null) {
                                    return;
                                }
                                setIsReverting(true);
                                dashboardActions.revertUserWidget(
                                    definition.id,
                                    reverting.number,
                                    () => setReverting(null),
                                    () => setIsReverting(false),
                                );
                            }}
                        >
                            {__('Go back')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
