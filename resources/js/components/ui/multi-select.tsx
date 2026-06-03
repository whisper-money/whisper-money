import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
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
import { __ } from '@/utils/i18n';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface MultiSelectOption {
    value: string;
    label: string;
    icon?: React.ReactNode;
    badgeClassName?: string;
    /** Optional hierarchy: indentation level and parent for tree-aware search. */
    depth?: number;
    parentValue?: string | null;
}

interface Props {
    options: MultiSelectOption[];
    selected: string[];
    onChange: (selected: string[]) => void;
    placeholder?: string;
    searchPlaceholder?: string;
    emptyText?: string;
    id?: string;
    className?: string;
}

export function MultiSelect({
    options,
    selected,
    onChange,
    placeholder = __('Select…'),
    searchPlaceholder = __('Search…'),
    emptyText = __('No results.'),
    id,
    className,
}: Props) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');

    const isTree = options.some(
        (option) => option.depth != null || option.parentValue != null,
    );

    // Tree-aware search: keep each match together with its ancestors so a
    // matching child still shows its parent chain for context.
    const visibleOptions = useMemo(() => {
        const query = search.trim().toLowerCase();
        if (!isTree || !query) {
            return options;
        }

        const parentOf = new Map(
            options.map((option) => [option.value, option.parentValue ?? null]),
        );
        const include = new Set<string>();
        for (const option of options) {
            if (!option.label.toLowerCase().includes(query)) {
                continue;
            }
            let value: string | null | undefined = option.value;
            let guard = 0;
            while (value != null && guard++ < 10) {
                include.add(value);
                value = parentOf.get(value);
            }
        }

        return options.filter((option) => include.has(option.value));
    }, [options, isTree, search]);

    const toggle = (value: string) => {
        if (selected.includes(value)) {
            onChange(selected.filter((item) => item !== value));
        } else {
            onChange([...selected, value]);
        }
    };

    const remove = (value: string) => {
        onChange(selected.filter((item) => item !== value));
    };

    const selectedOptions = options.filter((option) =>
        selected.includes(option.value),
    );

    return (
        <div className={cn('space-y-2', className)}>
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        role="combobox"
                        aria-expanded={open}
                        className="w-full justify-between font-normal"
                    >
                        <span className="truncate text-muted-foreground">
                            {selected.length > 0
                                ? __(':count selected', {
                                      count: selected.length,
                                  })
                                : placeholder}
                        </span>
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-[--radix-popover-trigger-width] p-0"
                    align="start"
                >
                    <Command shouldFilter={!isTree}>
                        <CommandInput
                            placeholder={searchPlaceholder}
                            value={search}
                            onValueChange={setSearch}
                        />
                        <CommandList>
                            {isTree ? (
                                visibleOptions.length === 0 && (
                                    <div className="py-6 text-center text-sm text-muted-foreground">
                                        {emptyText}
                                    </div>
                                )
                            ) : (
                                <CommandEmpty>{emptyText}</CommandEmpty>
                            )}
                            <CommandGroup>
                                {visibleOptions.map((option) => {
                                    const isSelected = selected.includes(
                                        option.value,
                                    );
                                    return (
                                        <CommandItem
                                            key={option.value}
                                            value={option.label}
                                            onSelect={() => toggle(option.value)}
                                            style={
                                                option.depth
                                                    ? {
                                                          paddingLeft: `${option.depth * 1.25}rem`,
                                                      }
                                                    : undefined
                                            }
                                        >
                                            <Check
                                                className={cn(
                                                    'mr-2 h-4 w-4 shrink-0',
                                                    isSelected
                                                        ? 'opacity-100'
                                                        : 'opacity-0',
                                                )}
                                            />
                                            {option.icon && (
                                                <span className="mr-2 flex items-center">
                                                    {option.icon}
                                                </span>
                                            )}
                                            {option.label}
                                        </CommandItem>
                                    );
                                })}
                            </CommandGroup>
                        </CommandList>
                    </Command>
                </PopoverContent>
            </Popover>

            {selectedOptions.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {selectedOptions.map((option) => (
                        <Badge
                            key={option.value}
                            variant={option.badgeClassName ? undefined : 'secondary'}
                            className={cn('gap-1', option.badgeClassName)}
                        >
                            {option.icon}
                            {option.label}
                            <button
                                type="button"
                                onClick={() => remove(option.value)}
                                className="rounded-full outline-none ring-offset-background hover:text-foreground focus:ring-2 focus:ring-ring"
                                aria-label={__('Remove :label', {
                                    label: option.label,
                                })}
                            >
                                <X className="h-3 w-3" />
                            </button>
                        </Badge>
                    ))}
                </div>
            )}
        </div>
    );
}
