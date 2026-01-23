import { destroy } from '@/actions/App/Http/Controllers/BudgetController';
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
import { Budget } from '@/types/budget';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    budget: Budget;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    redirectTo?: string;
}

export function DeleteBudgetDialog({
    budget,
    open,
    onOpenChange,
    redirectTo = '/budgets',
}: Props) {
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);

        router.delete(destroy({ budget: budget.id }).url, {
            onSuccess: () => {
                onOpenChange(false);
                if (redirectTo) {
                    router.visit(redirectTo);
                }
            },
            onFinish: () => setIsDeleting(false),
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{__('Delete Budget')}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {__('Are you sure you want to delete "')}
                        {budget.name}
                        {__(
                            '"? This\n                        action cannot be undone. All budget periods,\n                        allocations, and transaction assignments will be\n                        permanently removed.',
                        )}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isDeleting}>
                        {__('Cancel')}
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={handleDelete}
                        disabled={isDeleting}
                        variant="destructive"
                    >
                        {isDeleting ? 'Deleting...' : 'Delete'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
