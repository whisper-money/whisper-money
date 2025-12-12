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
import { labelSyncService } from '@/services/label-sync';
import { getLabelColorClasses, LABEL_COLORS, type Label } from '@/types/label';
import { Check, ChevronsUpDown, Plus, Tag, X } from 'lucide-react';
import { useState } from 'react';

interface LabelComboboxProps {
    value: string[];
    onValueChange: (value: string[]) => void;
    labels: Label[];
    onLabelsChange?: (labels: Label[]) => void;
    disabled?: boolean;
    placeholder?: string;
    triggerClassName?: string;
    allowCreate?: boolean;
}

export function LabelCombobox({
    value,
    onValueChange,
    labels,
    onLabelsChange,
    disabled = false,
    placeholder = 'Add labels...',
    triggerClassName,
    allowCreate = true,
}: LabelComboboxProps) {
    const [open, setOpen] = useState(false);
    const [inputValue, setInputValue] = useState('');
    const [isCreating, setIsCreating] = useState(false);

    const selectedLabels = labels.filter((l) => value.includes(l.id));

    const sortedLabels = [...labels].sort((a, b) =>
        a.name.localeCompare(b.name),
    );

    const handleSelect = (labelId: string) => {
        if (value.includes(labelId)) {
            onValueChange(value.filter((id) => id !== labelId));
        } else {
            onValueChange([...value, labelId]);
        }
    };

    const handleRemove = (labelId: string, e: React.MouseEvent) => {
        e.stopPropagation();
        onValueChange(value.filter((id) => id !== labelId));
    };

    const handleCreate = async () => {
        if (!inputValue.trim() || isCreating) return;

        const existingLabel = labels.find(
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
            const newLabel = await labelSyncService.findOrCreate(
                inputValue.trim(),
                randomColor,
            );

            if (newLabel) {
                onLabelsChange?.([...labels, newLabel]);
                onValueChange([...value, newLabel.id]);
                setInputValue('');
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
        !labels.some((l) => l.name.toLowerCase() === inputValue.toLowerCase());

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
                                        <button
                                            type="button"
                                            onClick={(e) =>
                                                handleRemove(label.id, e)
                                            }
                                            className="ml-0.5 rounded-full hover:bg-black/10"
                                        >
                                            <X className="h-3 w-3" />
                                        </button>
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
                        placeholder="Search or create labels..."
                        value={inputValue}
                        onValueChange={setInputValue}
                    />
                    <CommandList>
                        {sortedLabels.length === 0 && !showCreateOption && (
                            <CommandEmpty>No labels found.</CommandEmpty>
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
                                const isSelected = value.includes(label.id);
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
