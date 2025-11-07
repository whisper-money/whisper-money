import { useState } from 'react';
import { Form } from '@inertiajs/react';
import * as Icons from 'lucide-react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
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
import { Badge } from '@/components/ui/badge';
import {
    CATEGORY_ICONS,
    CATEGORY_COLORS,
    getCategoryColorClasses,
    type CategoryIcon,
    type CategoryColor,
} from '@/types/category';
import { store } from '@/actions/App/Http/Controllers/Settings/CategoryController';

export function CreateCategoryDialog() {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button>Create Category</Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[425px]">
                <DialogHeader>
                    <DialogTitle>Create Category</DialogTitle>
                    <DialogDescription>
                        Add a new category to organize your transactions.
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...store.form()}
                    onSuccess={() => setOpen(false)}
                    className="space-y-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="space-y-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    name="name"
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
                                <Select name="icon" required>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select an icon" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {CATEGORY_ICONS.map((iconName) => {
                                            const IconComponent =
                                                Icons[
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
                                <Select name="color" required>
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

                            <div className="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => setOpen(false)}
                                    disabled={processing}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={processing}>
                                    {processing ? 'Creating...' : 'Create'}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

