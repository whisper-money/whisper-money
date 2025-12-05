import { format } from 'date-fns';
import * as Icons from 'lucide-react';
import { Check, ChevronsUpDown, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { EncryptedText } from '@/components/encrypted-text';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { type Account } from '@/types/account';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type TransactionFilters as FiltersType } from '@/types/transaction';
import { CategoryIcon } from '../shared/category-combobox';

interface TransactionFiltersProps {
    filters: FiltersType;
    onFiltersChange: (filters: FiltersType) => void;
    categories: Category[];
    accounts: Account[];
    isKeySet: boolean;
    actions?: ReactNode;
    hideAccountFilter?: boolean;
}

export function TransactionFilters({
    filters,
    onFiltersChange,
    categories,
    accounts,
    isKeySet,
    actions,
    hideAccountFilter = false,
}: TransactionFiltersProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [categoryDropdownOpen, setCategoryDropdownOpen] = useState(false);
    const isUncategorizedSelected = filters.categoryIds.includes(
        UNCATEGORIZED_CATEGORY_ID,
    );

    function handleCategoryToggle(categoryId: number) {
        const newCategoryIds = filters.categoryIds.includes(categoryId)
            ? filters.categoryIds.filter((id) => id !== categoryId)
            : [...filters.categoryIds, categoryId];

        onFiltersChange({ ...filters, categoryIds: newCategoryIds });
    }

    function handleAccountToggle(accountId: number) {
        const newAccountIds = filters.accountIds.includes(accountId)
            ? filters.accountIds.filter((id) => id !== accountId)
            : [...filters.accountIds, accountId];

        onFiltersChange({ ...filters, accountIds: newAccountIds });
    }

    function clearFilters() {
        onFiltersChange({
            dateFrom: null,
            dateTo: null,
            amountMin: null,
            amountMax: null,
            categoryIds: [],
            accountIds: [],
            searchText: '',
        });
    }

    const activeFilterCount =
        (filters.dateFrom ? 1 : 0) +
        (filters.dateTo ? 1 : 0) +
        (filters.amountMin !== null ? 1 : 0) +
        (filters.amountMax !== null ? 1 : 0) +
        filters.categoryIds.length +
        (hideAccountFilter ? 0 : filters.accountIds.length);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <Input
                    placeholder={
                        isKeySet
                            ? 'Search description or notes...'
                            : 'Search disabled (encryption key not set)'
                    }
                    value={filters.searchText}
                    onChange={(e) =>
                        onFiltersChange({
                            ...filters,
                            searchText: e.target.value,
                        })
                    }
                    disabled={!isKeySet}
                    className="max-w-sm"
                />

                <Popover open={isOpen} onOpenChange={setIsOpen}>
                    <PopoverTrigger asChild>
                        <Button variant="outline">
                            Filters
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
                                <h4 className="font-medium">Filters</h4>
                            </div>

                            <div className="space-y-2">
                                <Label>Date</Label>
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
                                                    ? new Date(e.target.value)
                                                    : null,
                                            })
                                        }
                                        placeholder="From"
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
                                                    ? new Date(e.target.value)
                                                    : null,
                                            })
                                        }
                                        placeholder="To"
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Amount</Label>
                                <div className="grid grid-cols-2 gap-2 pt-2">
                                    <Input
                                        type="number"
                                        step="0.01"
                                        value={filters.amountMin ?? ''}
                                        onChange={(e) =>
                                            onFiltersChange({
                                                ...filters,
                                                amountMin: e.target.value
                                                    ? parseFloat(e.target.value)
                                                    : null,
                                            })
                                        }
                                        placeholder="Min"
                                    />
                                    <Input
                                        type="number"
                                        step="0.01"
                                        value={filters.amountMax ?? ''}
                                        onChange={(e) =>
                                            onFiltersChange({
                                                ...filters,
                                                amountMax: e.target.value
                                                    ? parseFloat(e.target.value)
                                                    : null,
                                            })
                                        }
                                        placeholder="Max"
                                    />
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Categories</Label>
                                <div className="pt-2">
                                    <Popover
                                        open={categoryDropdownOpen}
                                        onOpenChange={setCategoryDropdownOpen}
                                    >
                                        <PopoverTrigger asChild>
                                            <Button
                                                variant="outline"
                                                role="combobox"
                                                className="w-full justify-between"
                                            >
                                                {filters.categoryIds.length >
                                                0 ? (
                                                    <span className="truncate">
                                                        {
                                                            filters.categoryIds
                                                                .length
                                                        }{' '}
                                                        selected
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        Select categories...
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
                                                <CommandInput placeholder="Search categories..." />
                                                <CommandList>
                                                    <CommandEmpty>
                                                        No category found.
                                                    </CommandEmpty>
                                                    <CommandItem
                                                        onSelect={() =>
                                                            handleCategoryToggle(
                                                                UNCATEGORIZED_CATEGORY_ID,
                                                            )
                                                        }
                                                    >
                                                        <div
                                                            className={cn(
                                                                'mr-1 flex size-4 items-center justify-center rounded-sm border border-primary p-1',
                                                                isUncategorizedSelected
                                                                    ? 'bg-primary/10 text-primary-foreground'
                                                                    : 'opacity-50 [&_svg]:invisible',
                                                            )}
                                                        >
                                                            <Check className="size-3" />
                                                        </div>
                                                        <div className="flex items-center gap-2">
                                                            <div className="flex h-5 w-5 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
                                                                <Icons.HelpCircle className="h-3 w-3 text-zinc-500" />
                                                            </div>
                                                            Uncategorized
                                                        </div>
                                                    </CommandItem>
                                                    {categories.map(
                                                        (category) => {
                                                            const isSelected =
                                                                filters.categoryIds.includes(
                                                                    category.id,
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
                                                                    onSelect={() =>
                                                                        handleCategoryToggle(
                                                                            category.id,
                                                                        )
                                                                    }
                                                                >
                                                                    <div
                                                                        className={cn(
                                                                            'mr-1 flex size-4 items-center justify-center rounded-sm border border-primary p-1',
                                                                            isSelected
                                                                                ? 'bg-primary/10 text-primary-foreground'
                                                                                : 'opacity-50 [&_svg]:invisible',
                                                                        )}
                                                                    >
                                                                        <Check className="size-3" />
                                                                    </div>
                                                                    <div className="flex items-center gap-2">
                                                                        <div
                                                                            className={cn(
                                                                                'flex h-5 w-5 items-center justify-center rounded-full',
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
                                                                        <span>
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

                            {!hideAccountFilter && (
                                <div className="space-y-2">
                                    <Label>Accounts</Label>
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
                                                    <EncryptedText
                                                        encryptedText={
                                                            account.name
                                                        }
                                                        iv={account.name_iv}
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
                        </div>
                    </PopoverContent>
                </Popover>

                {activeFilterCount > 0 && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={clearFilters}
                        className="h-9"
                    >
                        <X className="mr-1 h-4 w-4" />
                        Clear
                    </Button>
                )}
                {actions ? (
                    <div className="ml-auto flex items-center gap-2">
                        {actions}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

const UNCATEGORIZED_CATEGORY_ID = -1;
