import { destroy } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type Category } from '@/types/category';
import { Form } from '@inertiajs/react';

interface DeleteCategoryDialogProps {
    category: Category;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function DeleteCategoryDialog({
    category,
    open,
    onOpenChange,
    onSuccess,
}: DeleteCategoryDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Delete Category</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete "{category.name}"? This
                        action cannot be undone.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...destroy.form.delete(category.id)}
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
