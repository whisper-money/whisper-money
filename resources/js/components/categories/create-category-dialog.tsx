import { store } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { CategoryForm } from '@/components/categories/category-form';
import { CreateButton } from '@/components/ui/create-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useControllableOpen } from '@/hooks/use-controllable-open';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';

interface CreateCategoryDialogProps {
    categories: Category[];
    onSuccess?: () => void;
    /** Preselected on every open; the new category starts with its color. */
    parent?: Category;
    /**
     * Passed together to open the dialog from elsewhere (a row menu): the
     * "Create Category" trigger button is then left out.
     */
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

export function CreateCategoryDialog({
    categories,
    onSuccess,
    parent,
    open,
    onOpenChange,
}: CreateCategoryDialogProps) {
    const {
        open: dialogOpen,
        setOpen,
        isControlled,
    } = useControllableOpen({ open, onOpenChange });

    return (
        <Dialog open={dialogOpen} onOpenChange={setOpen}>
            {!isControlled && (
                <DialogTrigger asChild>
                    <CreateButton>{__('Create Category')}</CreateButton>
                </DialogTrigger>
            )}
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Create Category')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Add a new category to organize your transactions.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <CategoryForm
                    action={store.form()}
                    categories={categories}
                    defaults={
                        parent
                            ? { parent_id: parent.id, color: parent.color }
                            : {}
                    }
                    submitLabel={__('Save')}
                    processingLabel={__('Saving...')}
                    onOpenChange={setOpen}
                    onSuccess={onSuccess}
                />
            </DialogContent>
        </Dialog>
    );
}
