import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
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
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import type { AutomationRule } from '@/types/automation-rule';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface DeleteAutomationRuleDialogProps {
    rule: AutomationRule;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function DeleteAutomationRuleDialog({
    rule,
    open,
    onOpenChange,
    onSuccess,
}: DeleteAutomationRuleDialogProps) {
    const [isDeleting, setIsDeleting] = useState(false);

    const handleDelete = async () => {
        setIsDeleting(true);
        router.delete(destroy(rule.id).url, {
            preserveState: true,
            preserveScroll: true,
            onSuccess: async () => {
                onOpenChange(false);
                await automationRuleSyncService.sync();
                onSuccess?.();
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
                        Are you sure you want to delete "{rule.title}"? This
                        action cannot be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel disabled={isDeleting}>
                        Cancel
                    </AlertDialogCancel>
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
