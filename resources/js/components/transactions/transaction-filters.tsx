import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { format } from 'date-fns';
import * as Icons from 'lucide-react';
import { ChevronsUpDown, Tag, X } from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';

import { AccountName } from '@/components/accounts/account-name';
import { SavedFilters } from '@/components/transactions/saved-filters';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    buildCategoryTree,
    categorySelectionState,
    flattenCategoryTree,
    toggleCategorySelection,
} from '@/lib/category-tree';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type Account } from '@/types/account';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { getLabelColorClasses, type Label } from '@/types/label';
import { type TransactionFilters as FiltersType } from '@/types/transaction';
import { CategoryIcon } from '../shared/category-combobox';

interface TransactionFiltersProps {
    filters: FiltersType;
    onFiltersChange: (filters: FiltersType) => void;
    categories: Category[];
    labels: Label[];
    accounts: Account[];
    actions?: ReactNode;
    hideAccountFilter?: boolean;
    enableSavedFilters?: boolean;
}

export function TransactionFilters({
    filters,
    onFiltersChange,
    categories,
    labels,
    accounts,
    actions,
    hideAccountFilter = false,
    enableSavedFilters = false,
}: TransactionFiltersProps) {
    const { features } = usePage<SharedData>().props;
    const [isOpen, setIsOpen] = useState(false);
    const [categoryDropdownOpen, setCategoryDropdownOpen] = useState(false);
    const [categorySearch, setCategorySearch] = useState('');
    const [labelDropdownOpen, setLabelDropdownOpen] = useState(false);
    const [searchText, setSearchText] = useState(filters.searchText);
    const [creditorName, setCreditorName] = useState(filters.creditorName);
    const [debtorName, setDebtorName] = useState(filters.debtorName);
    const isUncategorizedSelected = filters.categoryIds.includes(
        UNCATEGORIZED_CATEGORY_ID,
    );

    // Debounce text filter updates
    useEffect(() => {
        const timer = setTimeout(() => {
            if (
                searchText !== filters.searchText ||
                creditorName !== filters.creditorName ||
                debtorName !== filters.debtorName
            ) {
                onFiltersChange({
                    ...filters,
                    searchText,
                    creditorName,
                    debtorName,
                });
            }
        }, 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [searchText, creditorName, debtorName]);

    // Sync local state when filters change externally
    useEffect(() => {
        if (filters.searchText !== searchText) {
            setSearchText(filters.searchText);
        }
        if (filters.creditorName !== creditorName) {
            setCreditorName(filters.creditorName);
        }
        if (filters.debtorName !== debtorName) {
            setDebtorName(filters.debtorName);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [filters.searchText, filters.creditorName, filters.debtorName]);

    // Snapshot of which categories were selected when the dropdown opened.
    // Ordering uses this snapshot, so toggling while open never reshuffles the
    // list under the cursor; it only re-sorts the next time it is opened.
    const [categoryOrderSnapshot, setCategoryOrderSnapshot] = useState<
        Set<string>
    >(new Set());

    const categoryTree = useMemo(() => {
        // A branch sorts to the top when it (or a descendant) was selected.
        const branchSelected = new Set<string>();
        if (categoryOrderSnapshot.size > 0) {
            const parentOf = new Map(
                categories.map((c) => [c.id, c.parent_id]),
            );
            for (const id of categoryOrderSnapshot) {
                let current: string | null | undefined = id;
                let guard = 0;
                while (current != null && guard++ < 10) {
                    branchSelected.add(current);
                    current = parentOf.get(current);
                }
            }
        }

        const compare = (a: Category, b: Category) => {
            const aSelected = branchSelected.has(a.id);
            const bSelected = branchSelected.has(b.id);
            if (aSelected !== bSelected) {
                return aSelected ? -1 : 1;
            }
            return a.name.localeCompare(b.name);
        };

        return flattenCategoryTree(buildCategoryTree(categories, compare));
    }, [categories, categoryOrderSnapshot]);

    const selectedCategorySet = useMemo(
        () => new Set(filters.categoryIds),
        [filters.categoryIds],
    );

    // Tree-aware search: keep each match together with its ancestors so a
    // matching child still shows its parent chain for context.
    const visibleCategoryTree = useMemo(() => {
        const query = categorySearch.trim().toLowerCase();
        if (!query) {
            return categoryTree;
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

        return categoryTree.filter((node) => include.has(node.id));
    }, [categoryTree, categories, categorySearch]);

    const showUncategorizedRow =
        categorySearch.trim() === '' ||
        'uncategorized'.includes(categorySearch.trim().toLowerCase());
    const hasCategoryResults =
        visibleCategoryTree.length > 0 || showUncategorizedRow;

    function handleCategoryToggle(categoryId: string) {
        onFiltersChange({
            ...filters,
            categoryIds: toggleCategorySelection(
                filters.categoryIds,
                categoryId,
                categories,
            ),
        });
    }

    function handleAccountToggle(accountId: number) {
        const newAccountIds = filters.accountIds.includes(accountId)
            ? filters.accountIds.filter((id) => id !== accountId)
            : [...filters.accountIds, accountId];

        onFiltersChange({ ...filters, accountIds: newAccountIds });
    }

    function handleLabelToggle(labelId: string) {
        const newLabelIds = filters.labelIds.includes(labelId)
            ? filters.labelIds.filter((id) => id !== labelId)
            : [...filters.labelIds, labelId];

        onFiltersChange({ ...filters, labelIds: newLabelIds });
    }

    function clearFilters() {
        setSearchText('');
        setCreditorName('');
        setDebtorName('');
        onFiltersChange({
            dateFrom: null,
            dateTo: null,
            amountMin: null,
            amountMax: null,
            categoryIds: [],
            accountIds: [],
            labelIds: [],
            creditorName: '',
            debtorName: '',
            searchText: '',
            aiCategorizedOnly: false,
        });
    }

    const activeFilterCount =
        (filters.dateFrom ? 1 : 0) +
        (filters.dateTo ? 1 : 0) +
        (filters.amountMin !== null ? 1 : 0) +
        (filters.amountMax !== null ? 1 : 0) +
        filters.categoryIds.length +
        filters.labelIds.length +
        (filters.creditorName ? 1 : 0) +
        (filters.debtorName ? 1 : 0) +
        (filters.aiCategorizedOnly ? 1 : 0) +
        (hideAccountFilter ? 0 : filters.accountIds.length);

    return (
        <div className="space-y-4">
            <div className="flex flex-col gap-3">
                <div className="flex w-full flex-row items-center gap-2">
                    <Popover open={isOpen} onOpenChange={setIsOpen}>
                        <PopoverTrigger asChild>
                            <Button variant="outline">
                                {__('Filters')}

                                {activeFilterCount > 0 && (
                                    <Badge
                                        variant="secondary"
                                        className="ml-2 rounded-full px-1.5 py-0.5"
                                    >
                                        {activeFilterCount}
                                    </Badge>
                                )}
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            className="max-h-[600px] w-96 overflow-y-auto"
                            align="start"
                        >
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="font-medium">
                                        {__('Filters')}
                                    </h4>
                                </div>

                                <div className="space-y-2 md:hidden">
                                    <FormLabel>{__('Search')}</FormLabel>
                                    <Input
                                        placeholder={__(
                                            'Search description or notes...',
                                        )}
                                        value={searchText}
                                        onChange={(e) =>
                                            setSearchText(e.target.value)
                                        }
                                    />
                                </div>

                                <div className="space-y-2">
                                    <FormLabel>{__('Date')}</FormLabel>
                                    <div className="grid grid-cols-2 gap-2 pt-2">
                                        <Input
                                            type="date"
                                            value={
                                                filters.dateFrom
                                                    ? format(
                                                          filters.dateFrom,
                                                          'yyyy-MM-dd',
                                                      )
                                                    : ''
                                            }
                                            onChange={(e) =>
                                                onFiltersChange({
                                                    ...filters,
                                                    dateFrom: e.target.value
                                                        ? new Date(
                                                              e.target.value,
                                                          )
                                                        : null,
                                                })
                                            }
                                            placeholder={__('From')}
                                        />

                                        <Input
                                            type="date"
                                            value={
                                                filters.dateTo
                                                    ? format(
                                                          filters.dateTo,
                                                          'yyyy-MM-dd',
                                                      )
                                                    : ''
                                            }
                                            onChange={(e) =>
                                                onFiltersChange({
                                                    ...filters,
                                                    dateTo: e.target.value
                                                        ? new Date(
                                                              e.target.value,
                                                          )
                                                        : null,
                                                })
                                            }
                                            placeholder={__('To')}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <FormLabel>{__('Amount')}</FormLabel>
                                    <div className="grid grid-cols-2 gap-2 pt-2">
                                        <Input
                                            type="number"
                                            step="0.01"
                                            value={filters.amountMin ?? ''}
                                            onChange={(e) =>
                                                onFiltersChange({
                                                    ...filters,
                                                    amountMin: e.target.value
                                                        ? parseFloat(
                                                              e.target.value,
                                                          )
                                                        : null,
                                                })
                                            }
                                            placeholder={__('Min')}
                                        />

                                        <Input
                                            type="number"
                                            step="0.01"
                                            value={filters.amountMax ?? ''}
                                            onChange={(e) =>
                                                onFiltersChange({
                                                    ...filters,
                                                    amountMax: e.target.value
                                                        ? parseFloat(
                                                              e.target.value,
                                                          )
                                                        : null,
                                                })
                                            }
                                            placeholder={__('Max')}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <FormLabel>
                                        {__('Counterparties')}
                                    </FormLabel>
                                    <div className="grid grid-cols-2 gap-2 pt-2">
                                        <Input
                                            value={creditorName}
                                            onChange={(e) =>
                                                setCreditorName(e.target.value)
                                            }
                                            placeholder={__('Creditor name')}
                                        />
                                        <Input
                                            value={debtorName}
                                            onChange={(e) =>
                                                setDebtorName(e.target.value)
                                            }
                                            placeholder={__('Debtor name')}
                                        />
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <FormLabel>{__('Categories')}</FormLabel>
                                    <div className="pt-2">
                                        <Popover
                                            open={categoryDropdownOpen}
                                            onOpenChange={(open) => {
                                                if (open) {
                                                    setCategoryOrderSnapshot(
                                                        new Set(
                                                            filters.categoryIds,
                                                        ),
                                                    );
                                                } else {
                                                    setCategorySearch('');
                                                }
                                                setCategoryDropdownOpen(open);
                                            }}
                                        >
                                            <PopoverTrigger asChild>
                                                <Button
                                                    variant="outline"
                                                    role="combobox"
                                                    className="w-full justify-between"
                                                >
                                                    {filters.categoryIds
                                                        .length > 0 ? (
                                                        <span className="truncate">
                                                            {
                                                                filters
                                                                    .categoryIds
                                                                    .length
                                                            }{' '}
                                                            selected
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            {__(
                                                                'Select categories...',
                                                            )}
                                                        </span>
                                                    )}
                                                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent
                                                className="w-full p-0"
                                                align="start"
                                            >
                                                <Command shouldFilter={false}>
                                                    <CommandInput
                                                        placeholder={__(
                                                            'Search categories...',
                                                        )}
                                                        value={categorySearch}
                                                        onValueChange={
                                                            setCategorySearch
                                                        }
                                                    />
                                                    <CommandList>
                                                        {!hasCategoryResults && (
                                                            <div className="py-6 text-center text-sm text-muted-foreground">
                                                                {__(
                                                                    'No category found.',
                                                                )}
                                                            </div>
                                                        )}
                                                        {showUncategorizedRow && (
                                                            <CommandItem
                                                                onSelect={() =>
                                                                    handleCategoryToggle(
                                                                        UNCATEGORIZED_CATEGORY_ID,
                                                                    )
                                                                }
                                                            >
                                                                <Checkbox
                                                                    checked={
                                                                        isUncategorizedSelected
                                                                    }
                                                                    className="pointer-events-none mr-1"
                                                                />
                                                                <div className="flex items-center gap-2">
                                                                    <div className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                                        <Icons.HelpCircle className="h-3 w-3 text-zinc-500" />
                                                                    </div>
                                                                    {__(
                                                                        'Uncategorized',
                                                                    )}
                                                                </div>
                                                            </CommandItem>
                                                        )}
                                                        {visibleCategoryTree.map(
                                                            (category) => {
                                                                const state =
                                                                    categorySelectionState(
                                                                        category.id,
                                                                        selectedCategorySet,
                                                                        categories,
                                                                    );
                                                                const colorClasses =
                                                                    getCategoryColorClasses(
                                                                        category.color,
                                                                    );
                                                                return (
                                                                    <CommandItem
                                                                        key={
                                                                            category.id
                                                                        }
                                                                        value={
                                                                            category.name
                                                                        }
                                                                        onSelect={() =>
                                                                            handleCategoryToggle(
                                                                                category.id,
                                                                            )
                                                                        }
                                                                        style={{
                                                                            paddingLeft: `${0.5 + category.depth * 1.25}rem`,
                                                                        }}
                                                                    >
                                                                        <Checkbox
                                                                            checked={
                                                                                state ===
                                                                                'indeterminate'
                                                                                    ? 'indeterminate'
                                                                                    : state ===
                                                                                      'checked'
                                                                            }
                                                                            className="pointer-events-none mr-1"
                                                                        />
                                                                        <div className="flex min-w-0 items-center gap-2">
                                                                            <div
                                                                                className={cn(
                                                                                    'flex h-5 w-5 shrink-0 items-center justify-center rounded-full',
                                                                                    colorClasses.bg,
                                                                                )}
                                                                            >
                                                                                <CategoryIcon
                                                                                    category={
                                                                                        category
                                                                                    }
                                                                                    size={
                                                                                        4
                                                                                    }
                                                                                />
                                                                            </div>
                                                                            <span className="truncate">
                                                                                {
                                                                                    category.name
                                                                                }
                                                                            </span>
                                                                        </div>
                                                                    </CommandItem>
                                                                );
                                                            },
                                                        )}
                                                    </CommandList>
                                                </Command>
                                            </PopoverContent>
                                        </Popover>
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <FormLabel>{__('Labels')}</FormLabel>
                                    <div className="pt-2">
                                        <Popover
                                            open={labelDropdownOpen}
                                            onOpenChange={setLabelDropdownOpen}
                                        >
                                            <PopoverTrigger asChild>
                                                <Button
                                                    variant="outline"
                                                    role="combobox"
                                                    className="w-full justify-between"
                                                >
                                                    {filters.labelIds.length >
                                                    0 ? (
                                                        <span className="truncate">
                                                            {
                                                                filters.labelIds
                                                                    .length
                                                            }{' '}
                                                            selected
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            {__(
                                                                'Select labels...',
                                                            )}
                                                        </span>
                                                    )}
                                                    <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                                                </Button>
                                            </PopoverTrigger>
                                            <PopoverContent
                                                className="w-full p-0"
                                                align="start"
                                            >
                                                <Command>
                                                    <CommandInput
                                                        placeholder={__(
                                                            'Search labels...',
                                                        )}
                                                    />
                                                    <CommandList>
                                                        <CommandEmpty>
                                                            {__(
                                                                'No labels found.',
                                                            )}
                                                        </CommandEmpty>
                                                        {labels.map((label) => {
                                                            const isSelected =
                                                                filters.labelIds.includes(
                                                                    label.id,
                                                                );
                                                            const colorClasses =
                                                                getLabelColorClasses(
                                                                    label.color,
                                                                );
                                                            return (
                                                                <CommandItem
                                                                    key={
                                                                        label.id
                                                                    }
                                                                    onSelect={() =>
                                                                        handleLabelToggle(
                                                                            label.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <Checkbox
                                                                        checked={
                                                                            isSelected
                                                                        }
                                                                        className="pointer-events-none mr-1"
                                                                    />
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
                                                                        <span>
                                                                            {
                                                                                label.name
                                                                            }
                                                                        </span>
                                                                    </div>
                                                                </CommandItem>
                                                            );
                                                        })}
                                                    </CommandList>
                                                </Command>
                                            </PopoverContent>
                                        </Popover>
                                    </div>
                                </div>

                                {!hideAccountFilter && (
                                    <div className="space-y-2">
                                        <FormLabel>{__('Accounts')}</FormLabel>
                                        <div className="flex flex-wrap gap-2 pt-2">
                                            {accounts.map((account) => {
                                                const isSelected =
                                                    filters.accountIds.includes(
                                                        account.id,
                                                    );
                                                return (
                                                    <Badge
                                                        key={account.id}
                                                        variant={
                                                            isSelected
                                                                ? 'default'
                                                                : 'outline'
                                                        }
                                                        className="cursor-pointer px-2 py-1"
                                                        onClick={() =>
                                                            handleAccountToggle(
                                                                account.id,
                                                            )
                                                        }
                                                    >
                                                        <AccountName
                                                            account={account}
                                                            length={{
                                                                min: 6,
                                                                max: 28,
                                                            }}
                                                        />
                                                    </Badge>
                                                );
                                            })}
                                        </div>
                                    </div>
                                )}

                                <div className="space-y-2">
                                    <FormLabel>
                                        {__('Categorized by AI')}
                                    </FormLabel>
                                    <div className="flex flex-wrap gap-2 pt-2">
                                        <Badge
                                            variant={
                                                filters.aiCategorizedOnly
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="flex cursor-pointer items-center gap-1 px-2 py-1"
                                            onClick={() =>
                                                onFiltersChange({
                                                    ...filters,
                                                    aiCategorizedOnly:
                                                        !filters.aiCategorizedOnly,
                                                })
                                            }
                                        >
                                            <AiSparkleIcon className="h-3.5 w-3.5" />
                                            {__('Only show AI guesses')}
                                        </Badge>
                                    </div>
                                </div>
                            </div>
                        </PopoverContent>
                    </Popover>

                    <Input
                        placeholder={__('Search description or notes...')}
                        value={searchText}
                        onChange={(e) => setSearchText(e.target.value)}
                        className="hidden min-w-0 flex-1 md:block md:min-w-[350px]"
                    />

                    <div className="ml-auto flex items-center gap-2">
                        {enableSavedFilters && features.transactionAnalysis && (
                            <SavedFilters
                                filters={filters}
                                onLoad={onFiltersChange}
                            />
                        )}

                        {activeFilterCount > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={clearFilters}
                                className="h-9"
                            >
                                <X className="mr-1 h-4 w-4" />
                                {__('Clear')}
                            </Button>
                        )}
                    </div>
                </div>

                {actions ? <div className="w-full">{actions}</div> : null}
            </div>
        </div>
    );
}

const UNCATEGORIZED_CATEGORY_ID = 'uncategorized';
