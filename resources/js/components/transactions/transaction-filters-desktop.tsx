import { ShortcutKbd } from '@/components/shortcuts/shortcut-kbd';
import {
    SavedFilterDialogs,
    SavedFiltersMenu,
    useSavedFilters,
} from '@/components/transactions/saved-filters';
import {
    FacetEditor,
    facetEditorClassName,
    type FacetEditorProps,
    isListFacet,
} from '@/components/transactions/transaction-filters-facet-editor';
import { PeriodStepper } from '@/components/transactions/transaction-filters-period';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useDebouncedTextFilters } from '@/hooks/use-debounced-text-filters';
import { useLocale } from '@/hooks/use-locale';
import { useShortcut, useShortcutHint } from '@/hooks/use-shortcut';
import { CASHFLOW_BLOCK_TONES } from '@/lib/cashflow-block';
import {
    type FacetChip,
    facetChips,
    type FilterFacet,
    withoutFacets,
} from '@/lib/transaction-filter-facets';
import { cn } from '@/lib/utils';
import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type TransactionFilters } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import {
    ArrowLeftRight,
    ChevronLeft,
    Coins,
    Landmark,
    LayoutGrid,
    Plus,
    Search,
    Shapes,
    Tags,
    Users,
    X,
} from 'lucide-react';
import { type ComponentType, type ReactNode, useRef, useState } from 'react';

interface DesktopTransactionFiltersProps {
    filters: TransactionFilters;
    onFiltersChange: (filters: TransactionFilters) => void;
    categories: Category[];
    labels: Label[];
    accounts: Account[];
    hideAccountFilter: boolean;
    enableSavedFilters: boolean;
    /** At the far end of the toolbar, such as the column picker. */
    trailing?: ReactNode;
}

/**
 * The filters above a desktop list: a toolbar with the period stepper, the
 * search, "+ Filter" and the saved views, then one chip per active filter.
 * Every change applies at once.
 */
export function DesktopTransactionFilters({
    filters,
    onFiltersChange,
    categories,
    labels,
    accounts,
    hideAccountFilter,
    enableSavedFilters,
    trailing,
}: DesktopTransactionFiltersProps) {
    const locale = useLocale();
    const text = useDebouncedTextFilters(filters, onFiltersChange);
    const saved = useSavedFilters({
        filters,
        onLoad: onFiltersChange,
        enabled: enableSavedFilters,
    });
    const [openChip, setOpenChip] = useState<string | null>(null);

    const chips = facetChips(filters, {
        categories,
        accounts,
        labels,
        locale,
        hideAccountFilter,
    });

    const editorProps: Omit<FacetEditorProps, 'facet'> = {
        filters,
        onFiltersChange,
        categories,
        labels,
        accounts,
        text,
    };

    return (
        <div className="flex flex-col gap-3" data-test="transaction-filters">
            <div className="flex flex-wrap items-center gap-2">
                <PeriodStepper
                    dateFrom={filters.dateFrom}
                    dateTo={filters.dateTo}
                    onChange={(range) =>
                        onFiltersChange({ ...filters, ...range })
                    }
                />
                <SearchField
                    value={text.values.searchText}
                    onChange={(value) => text.setValue('searchText', value)}
                />
                <AddFilterButton
                    facets={availableFacets(filters, hideAccountFilter)}
                    editorProps={editorProps}
                />
                <div className="ml-auto flex items-center gap-1">
                    {enableSavedFilters && (
                        <SavedFiltersMenu saved={saved} labelled />
                    )}
                    {trailing}
                </div>
            </div>

            {chips.length > 0 && (
                <div
                    className="flex flex-wrap items-center gap-2"
                    data-testid="filter-chips"
                >
                    {chips.map((chip) => (
                        <FilterChip
                            key={chip.id}
                            chip={chip}
                            open={openChip === chip.id}
                            onOpenChange={(open) =>
                                setOpenChip(open ? chip.id : null)
                            }
                            onRemove={() =>
                                onFiltersChange(chip.remove(filters))
                            }
                            editorProps={editorProps}
                        />
                    ))}
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground"
                        onClick={() => onFiltersChange(withoutFacets(filters))}
                    >
                        {__('Clear all')}
                    </Button>
                    {enableSavedFilters && (
                        <>
                            <span
                                aria-hidden="true"
                                className="h-[18px] w-px bg-border"
                            />
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={saved.openSaveDialog}
                            >
                                {__('Save as view')}
                            </Button>
                        </>
                    )}
                </div>
            )}

            {enableSavedFilters && <SavedFilterDialogs saved={saved} />}
        </div>
    );
}

/** The search box; "/" anywhere on the page puts the cursor in it. */
function SearchField({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    const inputRef = useRef<HTMLInputElement>(null);
    const hint = useShortcutHint('transaction-filters.focus-search');

    useShortcut('transaction-filters.focus-search', (event) => {
        event.preventDefault();
        inputRef.current?.focus();
    });

    return (
        <label className="flex h-9 max-w-[460px] min-w-[240px] flex-[1_1_320px] items-center gap-2 rounded-md border border-input bg-transparent px-3 shadow-xs transition-[color,box-shadow] focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30">
            <Search className="h-4 w-4 shrink-0 text-muted-foreground" />
            <input
                ref={inputRef}
                type="search"
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={__('Search description or notes...')}
                aria-label={__('Search transactions')}
                aria-keyshortcuts={hint?.ariaKeyShortcuts}
                className="min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                data-testid="transaction-search"
            />
            <ShortcutKbd id="transaction-filters.focus-search" />
        </label>
    );
}

const FACET_MENU: {
    facet: FilterFacet;
    label: () => string;
    icon: ComponentType<{ className?: string }>;
}[] = [
    { facet: 'type', label: () => __('Type'), icon: ArrowLeftRight },
    { facet: 'amount', label: () => __('Amount'), icon: Coins },
    { facet: 'category', label: () => __('Category'), icon: Shapes },
    { facet: 'account', label: () => __('Account'), icon: Landmark },
    { facet: 'label', label: () => __('Label'), icon: Tags },
    { facet: 'counterparty', label: () => __('Counterparty'), icon: Users },
    { facet: 'ai', label: () => __('AI guesses only'), icon: AiSparkleIcon },
    {
        facet: 'cashflowBlock',
        label: () => __('Cashflow block'),
        icon: LayoutGrid,
    },
];

/**
 * What "+ Filter" offers: every facet but the accounts on a page already
 * narrowed to one, and the cashflow block only over a whole date range, the
 * only thing it can narrow.
 */
function availableFacets(
    filters: TransactionFilters,
    hideAccountFilter: boolean,
): FilterFacet[] {
    return FACET_MENU.map(({ facet }) => facet).filter((facet) => {
        if (facet === 'account') {
            return !hideAccountFilter;
        }
        if (facet === 'cashflowBlock') {
            return filters.dateFrom !== null && filters.dateTo !== null;
        }
        return true;
    });
}

/**
 * "+ Filter": a menu of facets, and once one is picked, its editor in the
 * same popover.
 */
function AddFilterButton({
    facets,
    editorProps,
}: {
    facets: FilterFacet[];
    editorProps: Omit<FacetEditorProps, 'facet'>;
}) {
    const [open, setOpen] = useState(false);
    const [facet, setFacet] = useState<FilterFacet | null>(null);

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                if (!next) {
                    setFacet(null);
                }
            }}
        >
            <PopoverTrigger asChild>
                <Button variant="outline" data-testid="add-filter-button">
                    <Plus className="h-4 w-4" />
                    {__('Filter')}
                </Button>
            </PopoverTrigger>
            <PopoverContent
                align="start"
                className={facet ? facetEditorClassName(facet) : 'w-56 p-1'}
                data-testid="add-filter-popover"
            >
                {facet ? (
                    <div className="flex flex-col">
                        <button
                            type="button"
                            onClick={() => setFacet(null)}
                            className={cn(
                                'flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground',
                                isListFacet(facet) ? 'px-3 pt-2' : 'pb-2',
                            )}
                        >
                            <ChevronLeft className="h-3.5 w-3.5" />
                            {__('Filters')}
                        </button>
                        <FacetEditor facet={facet} {...editorProps} />
                    </div>
                ) : (
                    <div role="menu" className="flex flex-col">
                        {FACET_MENU.filter((item) =>
                            facets.includes(item.facet),
                        ).map(({ facet: item, label, icon: Icon }) => (
                            <button
                                key={item}
                                type="button"
                                role="menuitem"
                                onClick={() => setFacet(item)}
                                className="flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:outline-none"
                            >
                                <Icon className="h-4 w-4 text-muted-foreground" />
                                {label()}
                            </button>
                        ))}
                    </div>
                )}
            </PopoverContent>
        </Popover>
    );
}

/**
 * "Name: value" for one active filter. The chip reopens the filter's editor
 * and its × takes the filter off.
 */
function FilterChip({
    chip,
    open,
    onOpenChange,
    onRemove,
    editorProps,
}: {
    chip: FacetChip;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onRemove: () => void;
    editorProps: Omit<FacetEditorProps, 'facet'>;
}) {
    const block = editorProps.filters.cashflowBlock;

    return (
        <span
            className="inline-flex h-[30px] max-w-full items-center gap-1 rounded-full border border-border bg-muted pr-1 pl-2.5 text-[13px] text-foreground"
            data-testid={`filter-chip-${chip.id}`}
        >
            <Popover open={open} onOpenChange={onOpenChange}>
                <PopoverTrigger asChild>
                    <button
                        type="button"
                        className="inline-flex min-w-0 items-center gap-1 rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {chip.facet === 'cashflowBlock' && block ? (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    CASHFLOW_BLOCK_TONES[block],
                                )}
                            />
                        ) : null}
                        <span className="text-muted-foreground">
                            {chip.name}
                        </span>
                        <span className="max-w-[260px] truncate font-medium">
                            {chip.value}
                        </span>
                    </button>
                </PopoverTrigger>
                <PopoverContent
                    align="start"
                    className={facetEditorClassName(chip.facet)}
                >
                    <FacetEditor facet={chip.facet} {...editorProps} />
                </PopoverContent>
            </Popover>
            <button
                type="button"
                onClick={onRemove}
                aria-label={__('Remove the :name filter', { name: chip.name })}
                className="inline-flex size-[22px] shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <X className="size-3" />
            </button>
        </span>
    );
}
