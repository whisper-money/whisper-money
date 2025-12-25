import { update } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { categorySyncService } from '@/services/category-sync';
import {
    CATEGORY_COLORS,
    CATEGORY_ICONS,
    CATEGORY_TYPES,
    getCategoryColorClasses,
    type Category,
} from '@/types/category';
import { Form } from '@inertiajs/react';
import * as Icons from 'lucide-react';
import { Info } from 'lucide-react';
import { useState } from 'react';

interface EditCategoryDialogProps {
    category: Category;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onSuccess?: () => void;
}

export function EditCategoryDialog({
    category,
    open,
    onOpenChange,
    onSuccess,
}: EditCategoryDialogProps) {
    const [selectedType, setSelectedType] = useState<string>(category.type);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent hasKeyboard className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Edit Category</DialogTitle>
                    <DialogDescription>
                        Update the category information.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...update.form.patch(category.id)}
                    onSuccess={async () => {
                        await categorySyncService.sync();
                        onOpenChange(false);
                        onSuccess?.();
                    }}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={category.name}
                                    placeholder="Category name"
                                    required
                                />
                                {errors.name && (
                                    <p className="text-sm text-red-500">
                                        {errors.name}
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="icon">Icon</Label>
                                <Select
                                    name="icon"
                                    defaultValue={category.icon}
                                    required
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select an icon" />
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
                                <Label htmlFor="color">Color</Label>
                                <Select
                                    name="color"
                                    defaultValue={category.color}
                                    required
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a color" />
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
                                                            {color}
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
                                <Label htmlFor="type">Type</Label>
                                <Select
                                    name="type"
                                    defaultValue={category.type}
                                    required
                                    onValueChange={setSelectedType}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORY_TYPES.map((type) => (
                                            <SelectItem key={type} value={type}>
                                                {type.charAt(0).toUpperCase() +
                                                    type.slice(1)}
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
                                            Transactions in this category will
                                            not be counted in top expenses or
                                            income. Transfer categories are
                                            mainly used for transactions between
                                            accounts.
                                        </AlertDescription>
                                    </Alert>
                                )}
                            </div>

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => onOpenChange(false)}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Updating...' : 'Update'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
