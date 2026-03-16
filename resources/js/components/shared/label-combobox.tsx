import { store } from '@/actions/App/Http/Controllers/Settings/LabelController';
import { normalizeLabelComboboxState } from '@/components/shared/label-combobox-state';
import { Badge } from '@/components/ui/badge';
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
import { getLabelColorClasses, LABEL_COLORS, type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { Check, ChevronsUpDown, Plus, Tag, X } from 'lucide-react';
import { useState } from 'react';

interface LabelComboboxProps {
    value?: string[] | null;
    onValueChange: (value: string[]) => void;
    labels?: Label[] | null;
    disabled?: boolean;
    placeholder?: string;
    triggerClassName?: string;
    allowCreate?: boolean;
    allowRemoveAll?: boolean;
    onLabelCreated?: (label: Label) => void;
}

export function LabelCombobox({
    value,
    onValueChange,
    labels = [],
    disabled = false,
    placeholder = 'Add labels...',
    triggerClassName,
    allowCreate = true,
    allowRemoveAll = false,
    onLabelCreated,
}: LabelComboboxProps) {
    const [open, setOpen] = useState(false);
    const [inputValue, setInputValue] = useState('');
    const [isCreating, setIsCreating] = useState(false);

    const { value: safeValue, labels: safeLabels } =
        normalizeLabelComboboxState(value, labels);

    const selectedLabels = safeLabels.filter((l) => safeValue.includes(l.id));

    const sortedLabels = [...safeLabels].sort((a, b) =>
        a.name.localeCompare(b.name),
    );

    const handleSelect = (labelId: string) => {
        if (safeValue.includes(labelId)) {
            onValueChange(safeValue.filter((id) => id !== labelId));
        } else {
            onValueChange([...safeValue, labelId]);
        }
    };

    const handleRemove = (labelId: string, e: React.MouseEvent) => {
        e.stopPropagation();
        onValueChange(safeValue.filter((id) => id !== labelId));
    };

    const handleCreate = async () => {
        if (!inputValue.trim() || isCreating) return;

        const existingLabel = safeLabels.find(
            (l) => l.name.toLowerCase() === inputValue.toLowerCase(),
        );
        if (existingLabel) {
            handleSelect(existingLabel.id);
            setInputValue('');
            return;
        }

        setIsCreating(true);
        try {
            const randomColor =
                LABEL_COLORS[Math.floor(Math.random() * LABEL_COLORS.length)];

            const response = await fetch(store.url(), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((row) => row.startsWith('XSRF-TOKEN='))
                            ?.split('=')[1] || '',
                    ),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    name: inputValue.trim(),
                    color: randomColor,
                }),
            });

            if (!response.ok) {
                throw new Error('Failed to create label');
            }

            const data = await response.json();
            const newLabel = data.data || data;

            if (newLabel) {
                onValueChange([...safeValue, newLabel.id]);
                setInputValue('');
                onLabelCreated?.(newLabel);
            }
        } catch (error) {
            console.error('Failed to create label:', error);
        } finally {
            setIsCreating(false);
        }
    };

    const showCreateOption =
        allowCreate &&
        inputValue.trim() &&
        !safeLabels.some(
            (l) => l.name.toLowerCase() === inputValue.toLowerCase(),
        );

    return (
        <Popover modal open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'min-h-10 w-full justify-between',
                        triggerClassName,
                    )}
                    disabled={disabled}
                    onClick={(e) => e.stopPropagation()}
                >
                    {selectedLabels.length > 0 ? (
                        <div className="flex flex-wrap gap-1">
                            {selectedLabels.map((label) => {
                                const colorClasses = getLabelColorClasses(
                                    label.color,
                                );
                                return (
                                    <Badge
                                        key={label.id}
                                        className={cn(
                                            'gap-1 px-2 py-0.5',
                                            colorClasses.bg,
                                            colorClasses.text,
                                        )}
                                    >
                                        <Tag className="h-3 w-3" />
                                        {label.name}
                                        <span
                                            role="button"
                                            tabIndex={0}
                                            onClick={(e) =>
                                                handleRemove(label.id, e)
                                            }
                                            onKeyDown={(e) => {
                                                if (
                                                    e.key === 'Enter' ||
                                                    e.key === ' '
                                                ) {
                                                    handleRemove(
                                                        label.id,
                                                        e as unknown as React.MouseEvent,
                                                    );
                                                }
                                            }}
                                            className="ml-0.5 cursor-pointer rounded-full hover:bg-black/10"
                                        >
                                            <X className="h-3 w-3" />
                                        </span>
                                    </Badge>
                                );
                            })}
                        </div>
                    ) : (
                        <span className="text-muted-foreground">
                            {placeholder}
                        </span>
                    )}
                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[300px] p-0" align="start">
                <Command shouldFilter={false}>
                    <CommandInput
                        placeholder={__('Search or create labels...')}
                        value={inputValue}
                        onValueChange={setInputValue}
                    />

                    <CommandList>
                        {sortedLabels.length === 0 && !showCreateOption && (
                            <CommandEmpty>
                                {__('No labels found.')}
                            </CommandEmpty>
                        )}
                        {allowRemoveAll && (
                            <CommandItem
                                onSelect={() => {
                                    onValueChange([]);
                                    setOpen(false);
                                }}
                                className="gap-2"
                            >
                                <X className="h-4 w-4" />
                                {__('Remove all labels')}
                            </CommandItem>
                        )}
                        {showCreateOption && (
                            <CommandItem
                                onSelect={handleCreate}
                                disabled={isCreating}
                                className="gap-2"
                            >
                                <Plus className="h-4 w-4" />
                                {isCreating
                                    ? 'Creating...'
                                    : `Create "${inputValue.trim()}"`}
                            </CommandItem>
                        )}
                        {sortedLabels
                            .filter(
                                (label) =>
                                    !inputValue ||
                                    label.name
                                        .toLowerCase()
                                        .includes(inputValue.toLowerCase()),
                            )
                            .map((label) => {
                                const colorClasses = getLabelColorClasses(
                                    label.color,
                                );
                                const isSelected = safeValue.includes(label.id);
                                return (
                                    <CommandItem
                                        key={label.id}
                                        value={label.name}
                                        onSelect={() => handleSelect(label.id)}
                                    >
                                        <div className="flex items-center gap-2">
                                            <div
                                                className={cn(
                                                    'flex h-5 w-5 items-center justify-center rounded-full',
                                                    colorClasses.bg,
                                                )}
                                            >
                                                <Tag
                                                    className={cn(
                                                        'h-3 w-3',
                                                        colorClasses.text,
                                                    )}
                                                />
                                            </div>
                                            <span className="truncate">
                                                {label.name}
                                            </span>
                                        </div>
                                        <Check
                                            className={cn(
                                                'ml-auto h-4 w-4',
                                                isSelected
                                                    ? 'opacity-100'
                                                    : 'opacity-0',
                                            )}
                                        />
                                    </CommandItem>
                                );
                            })}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}

export function LabelBadge({ label }: { label: Label }) {
    const colorClasses = getLabelColorClasses(label.color);
    return (
        <Badge
            className={cn(
                'gap-1 px-2 py-0.5',
                colorClasses.bg,
                colorClasses.text,
            )}
        >
            <Tag className="h-3 w-3" />
            {label.name}
        </Badge>
    );
}

export function LabelBadges({
    labels,
    max = 3,
}: {
    labels: Label[];
    max?: number;
}) {
    if (!labels || labels.length === 0) return null;

    const displayLabels = labels.slice(0, max);
    const remainingCount = labels.length - max;

    return (
        <div className="flex flex-wrap gap-1">
            {displayLabels.map((label) => (
                <LabelBadge key={label.id} label={label} />
            ))}
            {remainingCount > 0 && (
                <Badge variant="outline" className="px-2 py-0.5">
                    +{remainingCount}
                </Badge>
            )}
        </div>
    );
}
