import { archive } from '@/actions/App/Http/Controllers/SavingsGoalController';
import { ArchiveDialog } from '@/components/shared/archive-dialog';
import { SavingsGoal } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';

interface Props {
    savingsGoal: SavingsGoal;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function ArchiveSavingsGoalDialog({
    savingsGoal,
    open,
    onOpenChange,
}: Props) {
    return (
        <ArchiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={__('Archive savings goal')}
            description={__(
                'Archiving :name puts it away without deleting anything:',
                { name: savingsGoal.name },
            )}
            consequences={
                savingsGoal.kind === 'monthly'
                    ? [
                          __(
                              'This month becomes a partial month: what you saved stays, but it gets no verdict.',
                          ),
                          __(
                              'Month-end reminders stop, and its automation rule stops tagging transfers.',
                          ),
                          __(
                              'Every past month keeps its target and result. You can still open the goal to look at its history.',
                          ),
                      ]
                    : [
                          __(
                              'Its label is removed. The transactions that carry it keep their history but stop showing it, and you will not be able to pick that label again.',
                          ),
                          __(
                              'Any automation rule that adds that label stops adding it.',
                          ),
                          __(
                              'The amount saved is frozen at what it is today, whatever happens to those transactions afterwards.',
                          ),
                          __(
                              'You will not be able to edit it or link more transactions to it, but you can still open it to look at what you saved.',
                          ),
                      ]
            }
            archiveUrl={archive.url({ savingsGoal: savingsGoal.id })}
            confirmLabel={__('Archive goal')}
            failureMessage={__('The goal could not be archived.')}
        />
    );
}
