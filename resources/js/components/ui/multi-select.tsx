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
import { useState } from 'react';

export interface MultiSelectOption {
    value: string;
    label: string;
    icon?: React.ReactNode;
    badgeClassName?: string;
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
                    <Command>
                        <CommandInput placeholder={searchPlaceholder} />
                        <CommandList>
                            <CommandEmpty>{emptyText}</CommandEmpty>
                            <CommandGroup>
                                {options.map((option) => {
                                    const isSelected = selected.includes(
                                        option.value,
                                    );
                                    return (
                                        <CommandItem
                                            key={option.value}
                                            value={option.label}
                                            onSelect={() => toggle(option.value)}
                                        >
                                            <Check
                                                className={cn(
                                                    'mr-2 h-4 w-4',
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
