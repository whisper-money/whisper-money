import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { type Category, getCategoryColorClasses } from '@/types/category';
import * as Icons from 'lucide-react';

interface CategorySelectProps {
    value: string | null;
    onValueChange: (value: string) => void;
    categories: Category[];
    disabled?: boolean;
    placeholder?: string;
    className?: string;
    triggerClassName?: string;
    showUncategorized?: boolean;
}

export function CategorySelect({
    value,
    onValueChange,
    categories,
    disabled = false,
    placeholder = 'Select category',
    className,
    triggerClassName,
    showUncategorized = true,
}: CategorySelectProps) {
    const selectedCategory =
        value && value !== 'null'
            ? categories.find((c) => c.id === parseInt(value))
            : null;

    const colorClasses = selectedCategory
        ? getCategoryColorClasses(selectedCategory.color)
        : null;

    const SelectedIconComponent = selectedCategory
        ? (Icons[
              selectedCategory.icon as keyof typeof Icons
          ] as Icons.LucideIcon)
        : Icons.CircleQuestionMark;

    return (
        <Select
            value={value || 'null'}
            onValueChange={onValueChange}
            disabled={disabled}
        >
            <SelectTrigger className={triggerClassName}>
                <SelectValue>
                    {selectedCategory ? (
                        <Badge
                            className={`${colorClasses?.bg} ${colorClasses?.text} flex gap-2`}
                        >
                            <SelectedIconComponent
                                className={`h-2 w-2 opacity-80 ${colorClasses?.text}`}
                            />
                            {selectedCategory.name}
                        </Badge>
                    ) : (
                        <Badge className="flex gap-2 bg-zinc-50 text-zinc-500 dark:bg-zinc-950">
                            <Icons.CircleQuestionMark className="h-2 w-2 text-zinc-500 opacity-80" />
                            {placeholder}
                        </Badge>
                    )}
                </SelectValue>
            </SelectTrigger>
            <SelectContent className={className}>
                {showUncategorized && (
                    <SelectItem value="null">
                        <Badge className="flex items-center gap-2 bg-zinc-50 py-0.5 text-zinc-500 dark:bg-zinc-950">
                            <Icons.CircleQuestionMark className="h-2 w-2 text-zinc-500 opacity-80" />
                            <span>Uncategorized</span>
                        </Badge>
                    </SelectItem>
                )}
                {categories.map((category) => {
                    const IconComponent = Icons[
                        category.icon as keyof typeof Icons
                    ] as Icons.LucideIcon;
                    const classes = getCategoryColorClasses(category.color);
                    return (
                        <SelectItem
                            key={category.id}
                            value={String(category.id)}
                        >
                            <Badge
                                className={`flex items-center gap-2 py-0.5 ${classes.bg} ${classes.text}`}
                            >
                                <IconComponent
                                    className={`h-2 w-2 opacity-80 ${classes.text}`}
                                />
                                <span>{category.name}</span>
                            </Badge>
                        </SelectItem>
                    );
                })}
            </SelectContent>
        </Select>
    );
}

