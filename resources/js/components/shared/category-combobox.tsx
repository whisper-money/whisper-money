import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { Check, ChevronsUpDown, HelpCircle, type LucideIcon } from 'lucide-react';
import * as Icons from 'lucide-react';
import { memo, useState } from 'react';

const iconCache = new Map<string, LucideIcon>();

function getIconComponent(iconName?: string): LucideIcon | null {
    if (!iconName) return null;
    if (iconCache.has(iconName)) {
        return iconCache.get(iconName)!;
    }
    const icon = Icons[iconName as keyof typeof Icons] as LucideIcon | undefined;
    if (icon) {
        iconCache.set(iconName, icon);
    }
    return icon ?? null;
}

interface CategoryComboboxProps {
    value: string | null;
    onValueChange: (value: string) => void;
    categories: Category[];
    disabled?: boolean;
    placeholder?: string;
    triggerClassName?: string;
    showUncategorized?: boolean;
}

export function CategoryCombobox({
    value,
    onValueChange,
    categories,
    disabled = false,
    placeholder = 'Select category',
    triggerClassName,
    showUncategorized = true,
}: CategoryComboboxProps) {
    const [open, setOpen] = useState(false);

    const selectedCategory =
        value && value !== 'null'
            ? categories.find((c) => c.id === value)
            : null;

    const sortedCategories = [...categories].sort((a, b) =>
        a.name.localeCompare(b.name),
    );

    return (
        <Popover modal open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'max-w-full w-full justify-between !pl-2',
                        triggerClassName,
                    )}
                    disabled={disabled}
                >
                    {selectedCategory ? (
                        <div className="flex items-center gap-2 overflow-x-hidden">
                            <CategoryIcon category={selectedCategory} />
                            <span className='truncate'>{selectedCategory.name}</span>
                        </div>
                    ) : value === 'null' ? (
                        <div className="flex items-center gap-2">
                            <div className="flex h-6 w-6 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <HelpCircle className="h-3 w-3 text-zinc-500" />
                            </div>
                            <span className="text-zinc-500">Uncategorized</span>
                        </div>
                    ) : (
                        <span className="text-muted-foreground">
                            {placeholder}
                        </span>
                    )}
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="p-0" align="start">
                <Command>
                    <CommandInput placeholder="Search categories..." />
                    <CommandList>
                        <CommandEmpty>No category found.</CommandEmpty>
                        {showUncategorized && (
                            <CommandItem
                                value="uncategorized"
                                onSelect={() => {
                                    onValueChange('null');
                                    setOpen(false);
                                }}
                            >
                                <div className="flex items-center gap-2">
                                    <div className="flex h-6 w-6 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                        <HelpCircle className="h-3 w-3 text-zinc-500" />
                                    </div>
                                    <span>Uncategorized</span>
                                </div>
                                <Check
                                    className={cn(
                                        'ml-auto h-4 w-4',
                                        value === 'null'
                                            ? 'opacity-100'
                                            : 'opacity-0',
                                    )}
                                />
                            </CommandItem>
                        )}
                        {sortedCategories.map((category) => (
                            <CommandItem
                                key={category.id}
                                value={category.name}
                                onSelect={() => {
                                    onValueChange(String(category.id));
                                    setOpen(false);
                                }}
                            >
                                <div className="flex items-center gap-2">
                                    <CategoryIcon category={category} />
                                    <span className='truncate'>{category.name}</span>
                                </div>
                                <Check
                                    className={cn(
                                        'ml-auto h-4 w-4',
                                        value === String(category.id)
                                            ? 'opacity-100'
                                            : 'opacity-0',
                                    )}
                                />
                            </CommandItem>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export const CategoryIcon = memo(function CategoryIcon({ category, size = 5 }: { category: Category, size?: number }) {
    const colorClasses = getCategoryColorClasses(category.color);
    const iconName = category.icon;

    return (
        <div
            className={cn(
                'flex items-center justify-center rounded-full',
                `h-${size} w-${size}`,
                colorClasses.bg,
            )}
        >
            <DynamicIcon name={iconName} className={cn('h-3 w-3', colorClasses.text)} />
        </div>
    );
});

const DynamicIcon = memo(function DynamicIcon({ name, className }: { name?: string; className?: string }) {
    const Icon = getIconComponent(name);
    if (!Icon) return null;
    return <Icon className={className} />;
});

