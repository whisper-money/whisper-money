import { useState } from 'react';
import { router } from '@inertiajs/react';
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
import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import type { AutomationRule } from '@/types/automation-rule';

interface DeleteAutomationRuleDialogProps {
    rule: AutomationRule;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

export function DeleteAutomationRuleDialog({
    rule,
    open,
    onOpenChange,
}: DeleteAutomationRuleDialogProps) {
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = () => {
        setIsDeleting(true);
        router.delete(destroy(rule.id).url, {
            onSuccess: () => {
                onOpenChange(false);
            },
            onFinish: () => {
                setIsDeleting(false);
            },
        });
    };

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete Automation Rule</AlertDialogTitle>
                    <AlertDialogDescription>
                        Are you sure you want to delete "{rule.title}"? This action cannot
                        be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isDeleting}>Cancel</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={handleDelete}
                        disabled={isDeleting}
                        className="bg-red-600 hover:bg-red-700"
                    >
                        {isDeleting ? 'Deleting...' : 'Delete'}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

