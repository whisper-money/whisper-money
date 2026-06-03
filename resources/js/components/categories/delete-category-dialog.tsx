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
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type DeletionStrategy = 'reparent' | 'promote' | 'cascade';

interface DeleteCategoryDialogProps {
    category: Category;
    categories: Category[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function DeleteCategoryDialog({
    category,
    categories,
    open,
    onOpenChange,
    onSuccess,
}: DeleteCategoryDialogProps) {
    const hasChildren = useMemo(
        () => categories.some((c) => c.parent_id === category.id),
        [categories, category.id],
    );
    const isRoot = category.parent_id === null;
    const [strategy, setStrategy] = useState<DeletionStrategy>('reparent');

    const options: { value: DeletionStrategy; label: string }[] = [
        {
            value: 'reparent',
            label: isRoot
                ? __('Move child categories to the top level')
                : __('Move child categories up to the parent'),
        },
        { value: 'promote', label: __('Make child categories top level') },
        {
            value: 'cascade',
            label: __('Delete this category and all of its children'),
        },
    ];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Delete Category')}</DialogTitle>
                    <DialogDescription>
                        {__('Are you sure you want to delete')} “{category.name}
                        ”? {__('This action cannot be undone.')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...destroy.form.delete(category.id)}
                    onSuccess={() => {
                        onOpenChange(false);
                        onSuccess?.();
                    }}
                >
                    {({ processing, errors }) => (
                        <div className="space-y-4">
                            {hasChildren && (
                                <div className="space-y-2">
                                    <input
                                        type="hidden"
                                        name="strategy"
                                        value={strategy}
                                    />
                                    <Label>
                                        {__(
                                            'What should happen to its child categories?',
                                        )}
                                    </Label>
                                    <RadioGroup
                                        value={strategy}
                                        onValueChange={(value) =>
                                            setStrategy(
                                                value as DeletionStrategy,
                                            )
                                        }
                                    >
                                        {options.map((option) => (
                                            <Label
                                                key={option.value}
                                                className="flex items-center gap-2 text-sm font-normal"
                                            >
                                                <RadioGroupItem
                                                    value={option.value}
                                                />
                                                {option.label}
                                            </Label>
                                        ))}
                                    </RadioGroup>
                                    {strategy === 'cascade' && (
                                        <p className="text-sm text-red-500">
                                            {__(
                                                'Transactions in the deleted categories will become uncategorized.',
                                            )}
                                        </p>
                                    )}
                                </div>
                            )}
                            {errors.strategy && (
                                <p className="text-sm text-red-500">
                                    {errors.strategy}
                                </p>
                            )}
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
                                    {processing
                                        ? __('Deleting...')
                                        : __('Delete')}
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
