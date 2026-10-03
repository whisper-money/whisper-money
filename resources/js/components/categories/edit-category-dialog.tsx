import { update } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { CategoryForm } from '@/components/categories/category-form';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';

interface EditCategoryDialogProps {
    category: Category;
    categories: Category[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function EditCategoryDialog({
    category,
    categories,
    open,
    onOpenChange,
    onSuccess,
}: EditCategoryDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Edit Category')}</DialogTitle>
                    <DialogDescription>
                        {__('Update the category information.')}
                    </DialogDescription>
                </DialogHeader>
                <CategoryForm
                    action={update.form.patch(category.id)}
                    categories={categories}
                    defaults={category}
                    excludeId={category.id}
                    submitLabel={__('Update')}
                    processingLabel={__('Updating...')}
                    onOpenChange={onOpenChange}
                    onSuccess={onSuccess}
                />
            </DialogContent>
        </Dialog>
    );
}
