import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import * as Icons from 'lucide-react';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { type Category, getCategoryColorClasses } from '@/types/category';

interface BulkCategorySelectProps {
    categories: Category[];
    onCategoryChange: (categoryId: number | null) => void;
    disabled?: boolean;
}

export function BulkCategorySelect({
    categories,
    onCategoryChange,
    disabled = false,
}: BulkCategorySelectProps) {
    const [value, setValue] = useState<string>('');

    function handleChange(newValue: string) {
        setValue(newValue);
        const categoryId = newValue === 'null' ? null : parseInt(newValue);
        onCategoryChange(categoryId);
    }

    return (
        <Select value={value} onValueChange={handleChange} disabled={disabled}>
            <SelectTrigger className="h-9 w-[180px]">
                <SelectValue placeholder="Change category" />
            </SelectTrigger>
            <SelectContent>
                {categories.map((category) => {
                    const IconComponent = Icons[
                        category.icon as keyof typeof Icons
                    ] as Icons.LucideIcon;
                    const classes = getCategoryColorClasses(category.color);
                    return (
                        <SelectItem key={category.id} value={String(category.id)}>
                            <Badge
                                className={`flex items-center gap-2 py-0.5 ${classes.bg} ${classes.text}`}
                            >
                                <IconComponent
                                    className={`opacity-80 h-2 w-2 ${classes.text}`}
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

