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
                    <AlertDialogTitle>Delete Budget</AlertDialogTitle>
                    <AlertDialogDescription>
                        Are you sure you want to delete "{budget.name}"? This
                        action cannot be undone. All budget periods, allocations,
                        and transaction assignments will be permanently removed.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isDeleting}>
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        onClick={handleDelete}
                        disabled={isDeleting}
                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    >
                        {isDeleting ? 'Deleting...' : 'Delete'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

