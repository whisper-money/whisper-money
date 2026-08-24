import { update } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { CategoryCashflowDirectionFields } from '@/components/categories/category-cashflow-direction-fields';
import { ParentCategoryField } from '@/components/categories/parent-category-field';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    CATEGORY_COLORS,
    CATEGORY_ICONS,
    CATEGORY_TYPES,
    getCategoryColorClasses,
    getCategoryTypeLabel,
    type Category,
    type CategoryType,
} from '@/types/category';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';
import * as Icons from 'lucide-react';
import { useState } from 'react';

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
    const [selectedType, setSelectedType] = useState<CategoryType>(
        category.type,
    );
    const [parent, setParent] = useState<Category | null>(
        category.parent_id
            ? (categories.find((c) => c.id === category.parent_id) ?? null)
            : null,
    );

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Edit Category')}</DialogTitle>
                    <DialogDescription>
                        {__('Update the category information.')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...update.form.patch(category.id)}
                    onSuccess={() => {
                        onOpenChange(false);
                        onSuccess?.();
                    }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="name">{__('Name')}</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={category.name}
                                    placeholder={__('Category name')}
                                    required
                                />

                                <InputError message={errors.name} />
                            </div>

                            <ParentCategoryField
                                categories={categories}
                                value={parent?.id ?? null}
                                excludeId={category.id}
                                onChange={(next) => {
                                    setParent(next);
                                    if (next) {
                                        setSelectedType(next.type);
                                    }
                                }}
                                error={errors.parent_id}
                            />

                            <div className="space-y-2">
                                <Label htmlFor="icon">{__('Icon')}</Label>
                                <Select
                                    name="icon"
                                    defaultValue={category.icon}
                                    required
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={__('Select an icon')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORY_ICONS.map((iconName) => {
                                            const IconComponent = Icons[
                                                iconName as keyof typeof Icons
                                            ] as Icons.LucideIcon;
                                            return (
                                                <SelectItem
                                                    key={iconName}
                                                    value={iconName}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <IconComponent className="h-4 w-4" />
                                                        <span>{iconName}</span>
                                                    </div>
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.icon} />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="color">{__('Color')}</Label>
                                <Select
                                    name="color"
                                    defaultValue={category.color}
                                    required
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={__('Select a color')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORY_COLORS.map((color) => {
                                            const colorClasses =
                                                getCategoryColorClasses(color);
                                            return (
                                                <SelectItem
                                                    key={color}
                                                    value={color}
                                                >
                                                    <div className="flex items-center gap-2">
                                                        <Badge
                                                            className={`${colorClasses.bg} ${colorClasses.text}`}
                                                        >
                                                            {__(color)}
                                                        </Badge>
                                                    </div>
                                                </SelectItem>
                                            );
                                        })}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.color} />
                            </div>

                            {parent ? (
                                <div className="space-y-2">
                                    <Label>{__('Type')}</Label>
                                    <input
                                        type="hidden"
                                        name="type"
                                        value={parent.type}
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        {getCategoryTypeLabel(parent.type)}
                                        {' · '}
                                        {__('Inherited from parent')}
                                    </p>
                                </div>
                            ) : (
                                <>
                                    <div className="space-y-2">
                                        <Label htmlFor="type">
                                            {__('Type')}
                                        </Label>
                                        <Select
                                            name="type"
                                            defaultValue={category.type}
                                            required
                                            onValueChange={(value) =>
                                                setSelectedType(
                                                    value as CategoryType,
                                                )
                                            }
                                        >
                                            <SelectTrigger>
                                                <SelectValue
                                                    placeholder={__(
                                                        'Select a type',
                                                    )}
                                                />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {CATEGORY_TYPES.map((type) => (
                                                    <SelectItem
                                                        key={type}
                                                        value={type}
                                                    >
                                                        {getCategoryTypeLabel(
                                                            type,
                                                        )}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={errors.type} />
                                    </div>

                                    <CategoryCashflowDirectionFields
                                        selectedType={selectedType}
                                        defaultValue={
                                            category.cashflow_direction
                                        }
                                    />
                                </>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing
                                        ? __('Updating...')
                                        : __('Update')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
