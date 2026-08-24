import { store } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { CategoryCashflowDirectionFields } from '@/components/categories/category-cashflow-direction-fields';
import { ParentCategoryField } from '@/components/categories/parent-category-field';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CreateButton } from '@/components/ui/create-button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
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

export function CreateCategoryDialog({
    categories,
    onSuccess,
}: {
    categories: Category[];
    onSuccess?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [selectedType, setSelectedType] = useState<CategoryType | ''>('');
    const [parent, setParent] = useState<Category | null>(null);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <CreateButton>{__('Create Category')}</CreateButton>
            </DialogTrigger>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>{__('Create Category')}</DialogTitle>
                    <DialogDescription>
                        {__(
                            'Add a new category to organize your transactions.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    onSuccess={() => {
                        setOpen(false);
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
                                    placeholder={__('Category name')}
                                    required
                                />

                                <InputError message={errors.name} />
                            </div>

                            <ParentCategoryField
                                categories={categories}
                                value={parent?.id ?? null}
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
                                <Select name="icon" required>
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
                                <Select name="color" required>
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
                                        defaultValue="hidden"
                                    />
                                </>
                            )}

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                    disabled={processing}
                                >
                                    {__('Cancel')}
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? __('Saving...') : __('Save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
