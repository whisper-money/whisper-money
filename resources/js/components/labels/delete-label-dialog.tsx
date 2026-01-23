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
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
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
                    <DialogTitle>{__('Delete Label')}</DialogTitle>
                    <DialogDescription>
                        {__('Are you sure you want to delete "')}
                        {label.name}
                        {__(
                            '"? This\n                        action cannot be undone.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...destroy.form.delete(label.id)}
                    onSuccess={() => {
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
                                {__('Cancel')}
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
