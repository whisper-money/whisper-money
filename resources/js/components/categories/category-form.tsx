import { CategoryCashflowDirectionFields } from '@/components/categories/category-cashflow-direction-fields';
import { ParentCategoryField } from '@/components/categories/parent-category-field';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import { type RouteFormDefinition } from '@/wayfinder';
import { Form } from '@inertiajs/react';
import * as Icons from 'lucide-react';
import { useState } from 'react';

interface CategoryFormProps {
    /** Where the form submits: `store.form()` or `update.form.patch(id)`. */
    action: RouteFormDefinition<'post'>;
    categories: Category[];
    /**
     * Starting values, the parent included (`parent_id`); a missing one leaves
     * its field on the placeholder.
     */
    defaults: Partial<Category>;
    /** Category being edited — itself and its descendants can't be a parent. */
    excludeId?: UUID;
    submitLabel: string;
    processingLabel: string;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

/**
 * The fields shared by the create and edit category dialogs. Render it as a
 * child of `DialogContent`: Radix unmounts that subtree while the dialog is
 * closed, so the parent and type state below is rebuilt from `defaults` on
 * every open, just like the uncontrolled inputs beside it. Kept in the dialog
 * it would outlive the category it was built for.
 */
export function CategoryForm({
    action,
    categories,
    defaults,
    excludeId,
    submitLabel,
    processingLabel,
    onOpenChange,
    onSuccess,
}: CategoryFormProps) {
    const [parent, setParent] = useState<Category | null>(
        () =>
            categories.find((category) => category.id === defaults.parent_id) ??
            null,
    );
    const [selectedType, setSelectedType] = useState<CategoryType | ''>(
        defaults.type ?? parent?.type ?? '',
    );

    return (
        <Form
            {...action}
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
                            defaultValue={defaults.name}
                            placeholder={__('Category name')}
                            required
                        />

                        <InputError message={errors.name} />
                    </div>

                    <ParentCategoryField
                        categories={categories}
                        value={parent?.id ?? null}
                        excludeId={excludeId}
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
                            defaultValue={defaults.icon}
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
                            defaultValue={defaults.color}
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
                                        <SelectItem key={color} value={color}>
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
                                <Label htmlFor="type">{__('Type')}</Label>
                                <Select
                                    name="type"
                                    defaultValue={defaults.type}
                                    required
                                    onValueChange={(value) =>
                                        setSelectedType(value as CategoryType)
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue
                                            placeholder={__('Select a type')}
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORY_TYPES.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {getCategoryTypeLabel(type)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.type} />
                            </div>

                            <CategoryCashflowDirectionFields
                                selectedType={selectedType}
                                defaultValue={defaults.cashflow_direction}
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
                            {processing ? processingLabel : submitLabel}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
