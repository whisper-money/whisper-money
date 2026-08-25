import { archive } from '@/actions/App/Http/Controllers/BudgetController';
import { ArchiveDialog } from '@/components/shared/archive-dialog';
import { Budget } from '@/types/budget';
import { __ } from '@/utils/i18n';

interface Props {
    budget: Budget;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function ArchiveBudgetDialog({ budget, open, onOpenChange }: Props) {
    return (
        <ArchiveDialog
            open={open}
            onOpenChange={onOpenChange}
            title={__('Archive budget')}
            description={__(
                'Archiving :name puts it away without deleting anything:',
                { name: budget.name },
            )}
            consequences={[
                __(
                    'It stops counting from today. New transactions will never land in it again, not even ones dated before today.',
                ),
                __(
                    'The periods it already has keep the figures they have now, and you can still open the budget to look at them.',
                ),
                __(
                    'You will not be able to edit its name or its limit any more.',
                ),
                __(
                    'The categories and labels it was watching go back to your catch-all budget, if you have one.',
                ),
                __('It drops off your notification settings.'),
            ]}
            archiveUrl={archive.url({ budget: budget.id })}
            confirmLabel={__('Archive budget')}
            failureMessage={__('The budget could not be archived.')}
        />
    );
}
