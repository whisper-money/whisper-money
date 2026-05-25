import { store } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { CategoryCashflowDirectionFields } from '@/components/categories/category-cashflow-direction-fields';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
    type CategoryType,
} from '@/types/category';
import { __ } from '@/utils/i18n';
import { Form } from '@inertiajs/react';
import * as Icons from 'lucide-react';
import { Info } from 'lucide-react';
import { useState } from 'react';

export function CreateCategoryDialog({
    onSuccess,
}: {
    onSuccess?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [selectedType, setSelectedType] = useState<CategoryType | ''>('');

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

                                {errors.name && (
                                    <p className="text-sm text-red-500">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

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
                                {errors.icon && (
                                    <p className="text-sm text-red-500">
                                        {errors.icon}
                                    </p>
                                )}
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
                                {errors.color && (
                                    <p className="text-sm text-red-500">
                                        {errors.color}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="type">{__('Type')}</Label>
                                <Select
                                    name="type"
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
                                {errors.type && (
                                    <p className="text-sm text-red-500">
                                        {errors.type}
                                    </p>
                                )}
                                {selectedType === 'transfer' && (
                                    <Alert>
                                        <Info className="h-4 w-4 opacity-50" />
                                        <AlertDescription className="text-sm">
                                            {__(
                                                'Transactions in this category will\n                                            not be counted in top expenses or\n                                            income. Transfer categories are\n                                            mainly used for transactions between\n                                            accounts.',
                                            )}
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>

                            <CategoryCashflowDirectionFields
                                selectedType={selectedType}
                            />

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
                                    {processing
                                        ? __('Creating...')
                                        : __('Create')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
