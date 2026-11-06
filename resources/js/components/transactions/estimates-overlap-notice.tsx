import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useOverlappingEstimates } from '@/hooks/use-overlapping-estimates';
import { __ } from '@/utils/i18n';
import { CircleAlert, CircleCheck } from 'lucide-react';
import { useMemo } from 'react';

interface EstimatesOverlapNoticeProps {
    accountId: string | null | undefined;
    /** Dates of the movements about to be imported. */
    transactions: { transaction_date: string; selected?: boolean }[];
}

/**
 * Warns, before an import, that the account already holds "Estimate"
 * transactions in the months the file covers — the rough figures typed in
 * during onboarding — and offers to delete them in one go. Renders nothing
 * when there are none.
 */
export function EstimatesOverlapNotice({
    accountId,
    transactions,
}: EstimatesOverlapNoticeProps) {
    const dates = useMemo(
        () =>
            transactions
                .filter((transaction) => transaction.selected !== false)
                .map((transaction) => transaction.transaction_date),
        [transactions],
    );
    const { estimates, remove, isRemoving, removedCount } =
        useOverlappingEstimates(accountId, dates);

    if (estimates.length === 0) {
        return removedCount > 0 ? (
            <p
                role="status"
                className="flex items-center gap-2 rounded-lg bg-muted px-4 py-3 text-sm text-muted-foreground"
            >
                <CircleCheck className="size-4 shrink-0 text-success" />
                {removedCount === 1
                    ? __('1 estimate deleted.')
                    : __(':count estimates deleted.', { count: removedCount })}
            </p>
        ) : null;
    }

    return (
        <div
            role="alert"
            className="flex flex-col gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-100"
        >
            <p className="flex gap-2 text-pretty">
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                <span>
                    {estimates.length === 1
                        ? __(
                              'This account already has 1 estimate in the months this file covers. Keep it and that month counts twice.',
                          )
                        : __(
                              'This account already has :count estimates in the months this file covers. Keep them and those months count twice.',
                              { count: estimates.length },
                          )}
                </span>
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="self-start bg-background"
                disabled={isRemoving}
                onClick={() => void remove()}
            >
                {isRemoving && <Spinner />}
                {estimates.length === 1
                    ? __('Delete the estimate')
                    : __('Delete the :count estimates', {
                          count: estimates.length,
                      })}
            </Button>
        </div>
    );
}
