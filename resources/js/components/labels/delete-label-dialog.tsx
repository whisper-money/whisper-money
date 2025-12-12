import { destroy } from '@/actions/App/Http/Controllers/Settings/LabelController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { labelSyncService } from '@/services/label-sync';
import { type Label } from '@/types/label';
import { Form } from '@inertiajs/react';

interface DeleteLabelDialogProps {
    label: Label;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function DeleteLabelDialog({
    label,
    open,
    onOpenChange,
    onSuccess,
}: DeleteLabelDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Delete Label</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete "{label.name}"? This
                        action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...destroy.form.delete(label.id)}
                    onSuccess={async () => {
                        await labelSyncService.delete(label.id);
                        onOpenChange(false);
                        onSuccess?.();
                    }}
                >
                    {({ processing }) => (
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                                disabled={processing}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {processing ? 'Deleting...' : 'Delete'}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
