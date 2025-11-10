import { type ReactNode, useState } from 'react';
import * as Icons from 'lucide-react';
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
import { EncryptedText } from '@/components/encrypted-text';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type Account } from '@/types/account';
import { type TransactionFilters } from '@/types/transaction';

interface TransactionFiltersProps {
    filters: TransactionFilters;
    onFiltersChange: (filters: TransactionFilters) => void;
    categories: Category[];
    accounts: Account[];
    isKeySet: boolean;
    actions?: ReactNode;
}

export function TransactionFilters({
    filters,
    onFiltersChange,
    categories,
    accounts,
    isKeySet,
    actions,
}: TransactionFiltersProps) {
    const [isOpen, setIsOpen] = useState(false);
    const UncategorizedIcon = resolveIconComponent('CircleHelp');
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
                            </div>

                            <div className="space-y-2">
                                <Label>Date</Label>
                                <div className="pt-2 grid grid-cols-2 gap-2">
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
                                <Label>Amount</Label>
                                <div className="pt-2 grid grid-cols-2 gap-2">
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
                                <div className="pt-2 flex flex-wrap gap-2">
                                    <Badge
                                        variant={isUncategorizedSelected ? 'default' : 'outline'}
                                        className={`flex cursor-pointer items-center gap-1 py-1.5 ${isUncategorizedSelected
                                            ? 'border-transparent bg-muted text-foreground dark:bg-muted/40'
                                            : ''
                                            }`}
                                        onClick={() =>
                                            handleCategoryToggle(UNCATEGORIZED_CATEGORY_ID)
                                        }
                                    >
                                        <UncategorizedIcon className="h-4 w-4 opacity-80" />
                                        <span
                                            className={
                                                isUncategorizedSelected
                                                    ? ''
                                                    : 'text-muted-foreground'
                                            }
                                        >
                                            Uncategorized
                                        </span>
                                    </Badge>
                                    {categories.map((category) => {
                                        const isSelected =
                                            filters.categoryIds.includes(category.id);
                                        const IconComponent = resolveIconComponent(
                                            category.icon,
                                        );
                                        const colorClasses =
                                            getCategoryColorClasses(category.color);
                                        return (
                                            <Badge
                                                key={category.id}
                                                variant={
                                                    isSelected ? 'default' : 'outline'
                                                }
                                                className={`flex cursor-pointer items-center gap-1 py-1.5  ${isSelected ? `${colorClasses.bg} ${colorClasses.text} border-transparent` : ''}`}
                                                onClick={() =>
                                                    handleCategoryToggle(category.id)
                                                }
                                            >
                                                <IconComponent
                                                    className={`h-4 w-4 ${isSelected ? colorClasses.text : 'text-muted-foreground'}`}
                                                />
                                                <span
                                                    className={
                                                        isSelected
                                                            ? colorClasses.text
                                                            : 'text-muted-foreground'
                                                    }
                                                >
                                                    {category.name}
                                                </span>
                                            </Badge>
                                        );
                                    })}
                                </div>
                            </div>

                            <div className="space-y-2">
                                <Label>Accounts</Label>
                                <div className="pt-2 flex flex-wrap gap-2">
                                    {accounts.map((account) => {
                                        const isSelected =
                                            filters.accountIds.includes(account.id);
                                        return (
                                            <Badge
                                                key={account.id}
                                                variant={
                                                    isSelected ? 'default' : 'outline'
                                                }
                                                className="cursor-pointer py-1 px-2"
                                                onClick={() =>
                                                    handleAccountToggle(account.id)
                                                }
                                            >
                                                <EncryptedText
                                                    encryptedText={account.name}
                                                    iv={account.name_iv}
                                                    length={{ min: 6, max: 28 }}
                                                />
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
                        <Icons.X className="mr-1 h-4 w-4" />
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
const FALLBACK_ICON_NAMES = [
    'CircleHelp',
    'HelpCircle',
    'CircleQuestion',
    'CircleQuestionMark',
    'Circle',
];

function resolveIconComponent(iconName?: string): Icons.LucideIcon {
    if (iconName) {
        const icon = Icons[iconName as keyof typeof Icons];
        return icon as Icons.LucideIcon;
    }

    for (const fallbackName of FALLBACK_ICON_NAMES) {
        const fallback = Icons[fallbackName as keyof typeof Icons];
        if (typeof fallback === 'function') {
            return fallback as Icons.LucideIcon;
        }
    }

    return Icons.Circle as Icons.LucideIcon;
}

