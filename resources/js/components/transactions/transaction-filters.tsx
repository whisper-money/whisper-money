import { useState } from 'react';
import { Calendar, X } from 'lucide-react';
import { format } from 'date-fns';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Badge } from '@/components/ui/badge';
import { type Category } from '@/types/category';
import { type Account } from '@/types/account';
import { type TransactionFilters } from '@/types/transaction';

interface TransactionFiltersProps {
    filters: TransactionFilters;
    onFiltersChange: (filters: TransactionFilters) => void;
    categories: Category[];
    accounts: Account[];
    isKeySet: boolean;
}

export function TransactionFilters({
    filters,
    onFiltersChange,
    categories,
    accounts,
    isKeySet,
}: TransactionFiltersProps) {
    const [isOpen, setIsOpen] = useState(false);

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
        filters.accountIds.length;

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
                    <PopoverContent className="w-96" align="start">
                        <div className="space-y-4">
                            <div className="flex items-center justify-between">
                                <h4 className="font-medium">Filters</h4>
                                {activeFilterCount > 0 && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={clearFilters}
                                    >
                                        Clear all
                                    </Button>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label>Date Range</Label>
                                <div className="grid grid-cols-2 gap-2">
                                    <Input
                                        type="date"
                                        value={
                                            filters.dateFrom
                                                ? format(filters.dateFrom, 'yyyy-MM-dd')
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
                                                ? format(filters.dateTo, 'yyyy-MM-dd')
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
                                <Label>Amount Range</Label>
                                <div className="grid grid-cols-2 gap-2">
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
                                <div className="flex flex-wrap gap-2">
                                    {categories.map((category) => {
                                        const isSelected =
                                            filters.categoryIds.includes(category.id);
                                        return (
                                            <Badge
                                                key={category.id}
                                                variant={
                                                    isSelected ? 'default' : 'outline'
                                                }
                                                className="cursor-pointer"
                                                onClick={() =>
                                                    handleCategoryToggle(category.id)
                                                }
                                            >
                                                {category.icon} {category.name}
                                            </Badge>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Accounts</Label>
                                <div className="flex flex-wrap gap-2">
                                    {accounts.map((account) => {
                                        const isSelected =
                                            filters.accountIds.includes(account.id);
                                        return (
                                            <Badge
                                                key={account.id}
                                                variant={
                                                    isSelected ? 'default' : 'outline'
                                                }
                                                className="cursor-pointer"
                                                onClick={() =>
                                                    handleAccountToggle(account.id)
                                                }
                                            >
                                                {account.name}
                                            </Badge>
                                        );
                                    })}
                                </div>
                            </div>
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
            </div>
        </div>
    );
}

