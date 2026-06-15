import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    buildCategoryTree,
    flattenCategoryTree,
    getCategoryPath,
} from '@/lib/category-tree';
import { cn } from '@/lib/utils';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { __ } from '@/utils/i18n';
import * as Icons from 'lucide-react';
import {
    Check,
    ChevronsUpDown,
    HelpCircle,
    type LucideIcon,
} from 'lucide-react';
import {
    memo,
    type ReactNode,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

const iconCache = new Map<string, LucideIcon>();

function getIconComponent(iconName?: string): LucideIcon | null {
    if (!iconName) return null;
    if (iconCache.has(iconName)) {
        return iconCache.get(iconName)!;
    }
    const icon = Icons[iconName as keyof typeof Icons] as
        | LucideIcon
        | undefined;
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
    withoutChevronIcon?: boolean;
    /** Label for the empty / "no category" option (defaults to Uncategorized). */
    emptyOptionLabel?: string;
    /** Optional content rendered at the very top of the open dropdown. */
    header?: ReactNode;
    'data-testid'?: string;
}

export function CategoryCombobox({
    value,
    onValueChange,
    categories,
    disabled = false,
    placeholder = 'Select category',
    triggerClassName,
    showUncategorized = true,
    withoutChevronIcon = false,
    emptyOptionLabel,
    header,
    'data-testid': dataTestId,
}: CategoryComboboxProps) {
    const noneLabel = emptyOptionLabel ?? __('Uncategorized');
    const [open, setOpen] = useState(false);
    const [filterValue, setFilterValue] = useState('');
    const listRef = useRef<HTMLDivElement>(null);

    const selectedCategory =
        value && value !== 'null'
            ? categories.find((c) => c.id === value)
            : null;

    const orderedNodes = useMemo(
        () => flattenCategoryTree(buildCategoryTree(categories)),
        [categories],
    );

    const query = filterValue.trim().toLowerCase();

    // Tree-aware search: keep every match plus its ancestors, so a matching
    // child is always shown under its parent for context.
    const visibleNodes = useMemo(() => {
        if (!query) {
            return orderedNodes;
        }

        const parentOf = new Map(categories.map((c) => [c.id, c.parent_id]));
        const include = new Set<string>();
        for (const category of categories) {
            if (!category.name.toLowerCase().includes(query)) {
                continue;
            }
            let id: string | null | undefined = category.id;
            let guard = 0;
            while (id != null && guard++ < 10) {
                include.add(id);
                id = parentOf.get(id);
            }
        }

        return orderedNodes.filter((node) => include.has(node.id));
    }, [orderedNodes, categories, query]);

    const showNone =
        showUncategorized &&
        (query === '' ||
            noneLabel.toLowerCase().includes(query) ||
            'uncategorized'.includes(query));
    const hasResults = visibleNodes.length > 0 || showNone;

    useEffect(() => {
        if (filterValue && listRef.current) {
            listRef.current.scrollTop = 0;
        }
    }, [filterValue]);

    useEffect(() => {
        if (open && listRef.current) {
            listRef.current.scrollTop = 0;
        }
        if (!open) {
            setFilterValue('');
        }
    }, [open]);

    return (
        <Popover modal open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="outline"
                    role="combobox"
                    aria-expanded={open}
                    className={cn(
                        'w-full max-w-full justify-between !pr-2 !pl-1',
                        triggerClassName,
                    )}
                    disabled={disabled}
                    onClick={(e) => e.stopPropagation()}
                    data-testid={dataTestId}
                >
                    {selectedCategory ? (
                        <div className="flex items-center gap-2 overflow-x-hidden">
                            <CategoryIcon category={selectedCategory} />
                            <span className="truncate">
                                {selectedCategory.name}
                            </span>
                        </div>
                    ) : value === 'null' ? (
                        <div className="flex items-center gap-2 truncate">
                            <div className="flex h-6 w-6 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <HelpCircle className="h-3 w-3 text-zinc-500" />
                            </div>
                            <span className="truncate text-zinc-500">
                                {noneLabel}
                            </span>
                        </div>
                    ) : (
                        <span className="truncate text-muted-foreground">
                            {placeholder}
                        </span>
                    )}
                    {withoutChevronIcon === false && (
                        <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="p-0" align="start">
                {header}
                <Command shouldFilter={false}>
                    <CommandInput
                        placeholder={__('Search categories...')}
                        value={filterValue}
                        onValueChange={setFilterValue}
                    />

                    <CommandList ref={listRef}>
                        {!hasResults && (
                            <div className="py-6 text-center text-sm text-muted-foreground">
                                {__('No category found.')}
                            </div>
                        )}
                        {showNone && (
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
                                    <span>{noneLabel}</span>
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
                        {visibleNodes.map((category) => (
                            <CommandItem
                                key={category.id}
                                value={getCategoryPath(category.id, categories)}
                                onSelect={() => {
                                    onValueChange(String(category.id));
                                    setOpen(false);
                                }}
                            >
                                <div
                                    className="flex items-center gap-2"
                                    style={{
                                        paddingLeft: `${category.depth * 1.25}rem`,
                                    }}
                                >
                                    <CategoryIcon category={category} />
                                    <span className="truncate">
                                        {category.name}
                                    </span>
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

export const CategoryIcon = memo(function CategoryIcon({
    category,
}: {
    category: Category;
}) {
    const colorClasses = getCategoryColorClasses(category.color);
    const iconName = category.icon;

    return (
        <div
            className={cn(
                'flex aspect-square items-center justify-center rounded-full p-1',
                colorClasses.bg,
            )}
        >
            <DynamicIcon
                name={iconName}
                className={cn(`size-4 sm:size-3.5`, colorClasses.text)}
            />
        </div>
    );
});

export function CategoryBadge({ category }: { category: Category }) {
    const colorClasses = getCategoryColorClasses(category.color);

    return (
        <Badge
            className={cn(
                'gap-1 px-2 py-0.5',
                colorClasses.bg,
                colorClasses.text,
            )}
        >
            <DynamicIcon name={category.icon} className="h-3 w-3 opacity-80" />
            {category.name}
        </Badge>
    );
}

const DynamicIcon = memo(function DynamicIcon({
    name,
    className,
}: {
    name?: string;
    className?: string;
}) {
    const Icon = getIconComponent(name);
    if (!Icon) return null;
    return <Icon className={className} />;
});
