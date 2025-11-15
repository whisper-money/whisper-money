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
import { Check, ChevronsUpDown, HelpCircle } from 'lucide-react';
import * as Icons from 'lucide-react';
import { useState } from 'react';

function resolveIconComponent(iconName?: string): Icons.LucideIcon {
    const icon = Icons[iconName as keyof typeof Icons];
    return icon as Icons.LucideIcon;
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
                        'w-full justify-between !pl-2',
                        triggerClassName,
                    )}
                    disabled={disabled}
                >
                    {selectedCategory ? (
                        <div className="flex items-center gap-2">
                            <CategoryIcon category={selectedCategory} />
                            <span>{selectedCategory.name}</span>
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

function CategoryIcon({ category }: { category: Category }) {
    const colorClasses = getCategoryColorClasses(category.color);
    const IconComponent = resolveIconComponent(category.icon);

    return (
        <div
            className={cn(
                'flex h-5 w-5 items-center justify-center rounded-full',
                colorClasses.bg,
            )}
        >
            <IconComponent className={cn('h-3 w-3', colorClasses.text)} />
        </div>
    );
}

