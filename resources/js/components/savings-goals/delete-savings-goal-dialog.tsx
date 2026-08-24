import { destroy } from '@/actions/App/Http/Controllers/SavingsGoalController';
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
import { SavingsGoal } from '@/types/savings-goal';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface Props {
    savingsGoal: SavingsGoal;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    redirectTo?: string;
}

export function DeleteSavingsGoalDialog({
    savingsGoal,
    open,
    onOpenChange,
    redirectTo,
}: Props) {
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);

        router.delete(destroy({ savingsGoal: savingsGoal.id }).url, {
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
                    <AlertDialogTitle>
                        {__('Delete Savings Goal')}
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        {__(
                            'Are you sure you want to delete this savings goal? Its label will also be removed from your transactions. This action cannot be undone.',
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
                        {isDeleting ? __('Deleting...') : __('Delete')}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
