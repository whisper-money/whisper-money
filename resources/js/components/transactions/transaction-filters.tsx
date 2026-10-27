import { __ } from '@/utils/i18n';
import { ChevronsUpDown, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { SavedFilters } from '@/components/transactions/saved-filters';
import { AmountFilterFields } from '@/components/transactions/transaction-filters-amount';
import { CashflowBlockChip } from '@/components/transactions/transaction-filters-cashflow-block-chip';
import { DesktopTransactionFilters } from '@/components/transactions/transaction-filters-desktop';
import {
    AccountFacetList,
    CategoryFacetList,
    LabelFacetList,
} from '@/components/transactions/transaction-filters-facets';
import { PeriodFields } from '@/components/transactions/transaction-filters-period';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useDebouncedTextFilters } from '@/hooks/use-debounced-text-filters';
import { useIsMobile } from '@/hooks/use-mobile';
import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type TransactionFilters as FiltersType } from '@/types/transaction';

interface TransactionFiltersProps {
    filters: FiltersType;
    onFiltersChange: (filters: FiltersType) => void;
    categories: Category[];
    labels: Label[];
    accounts: Account[];
    /** Below the filters on a phone: the page's toolbar. */
    actions?: ReactNode;
    /** At the far end of the desktop toolbar, such as the column picker. */
    trailing?: ReactNode;
    hideAccountFilter?: boolean;
    enableSavedFilters?: boolean;
}

/**
 * The filters above a transactions list: the chips toolbar on desktop, the
 * "Filters" panel on a phone.
 */
export function TransactionFilters({
    hideAccountFilter = false,
    enableSavedFilters = false,
    ...props
}: TransactionFiltersProps) {
    const isMobile = useIsMobile();

    if (!isMobile) {
        return (
            <div className="flex flex-col gap-3">
                {props.actions ? (
                    <div className="flex justify-end">{props.actions}</div>
                ) : null}
                <DesktopTransactionFilters
                    {...props}
                    hideAccountFilter={hideAccountFilter}
                    enableSavedFilters={enableSavedFilters}
                />
            </div>
        );
    }

    return (
        <MobileTransactionFilters
            {...props}
            hideAccountFilter={hideAccountFilter}
            enableSavedFilters={enableSavedFilters}
        />
    );
}

const EMPTY_FILTERS: FiltersType = {
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
    cashflowBlock: null,
};

function MobileTransactionFilters({
    filters,
    onFiltersChange,
    categories,
    labels,
    accounts,
    actions,
    hideAccountFilter,
    enableSavedFilters,
}: TransactionFiltersProps & {
    hideAccountFilter: boolean;
    enableSavedFilters: boolean;
}) {
    const [isOpen, setIsOpen] = useState(false);
    const text = useDebouncedTextFilters(filters, onFiltersChange);

    const update = (changes: Partial<FiltersType>) =>
        onFiltersChange({ ...filters, ...changes });

    const activeFilterCount =
        (filters.dateFrom ? 1 : 0) +
        (filters.dateTo ? 1 : 0) +
        // One amount filter, though a single typed bound can set both ends.
        (filters.amountMin !== null || filters.amountMax !== null ? 1 : 0) +
        filters.categoryIds.length +
        filters.labelIds.length +
        (filters.creditorName ? 1 : 0) +
        (filters.debtorName ? 1 : 0) +
        (filters.aiCategorizedOnly ? 1 : 0) +
        (filters.cashflowBlock ? 1 : 0) +
        (hideAccountFilter ? 0 : filters.accountIds.length);

    return (
        <div className="space-y-4" data-test="transaction-filters">
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
                            data-test="transaction-filters-panel"
                        >
                            <div className="space-y-4">
                                <div className="flex items-center justify-between">
                                    <h4 className="font-medium">
                                        {__('Filters')}
                                    </h4>
                                </div>

                                <div className="space-y-2">
                                    <FormLabel>{__('Search')}</FormLabel>
                                    <Input
                                        placeholder={__(
                                            'Search description or notes...',
                                        )}
                                        value={text.values.searchText}
                                        onChange={(e) =>
                                            text.setValue(
                                                'searchText',
                                                e.target.value,
                                            )
                                        }
                                    />
                                </div>

                                <PeriodFields
                                    dateFrom={filters.dateFrom}
                                    dateTo={filters.dateTo}
                                    onChange={update}
                                />

                                <div className="space-y-2">
                                    <FormLabel>{__('Amount')}</FormLabel>
                                    <AmountFilterFields
                                        amountMin={filters.amountMin}
                                        amountMax={filters.amountMax}
                                        onChange={update}
                                    />
                                </div>

                                <FacetDropdown
                                    label={__('Categories')}
                                    placeholder={__('Select categories...')}
                                    count={filters.categoryIds.length}
                                    testId="filter-categories"
                                >
                                    <CategoryFacetList
                                        categories={categories}
                                        selectedIds={filters.categoryIds}
                                        onChange={(categoryIds) =>
                                            update({ categoryIds })
                                        }
                                    />
                                </FacetDropdown>

                                <FacetDropdown
                                    label={__('Labels')}
                                    placeholder={__('Select labels...')}
                                    count={filters.labelIds.length}
                                    testId="filter-labels"
                                >
                                    <LabelFacetList
                                        labels={labels}
                                        selectedIds={filters.labelIds}
                                        onChange={(labelIds) =>
                                            update({ labelIds })
                                        }
                                    />
                                </FacetDropdown>

                                {!hideAccountFilter && (
                                    <FacetDropdown
                                        label={__('Accounts')}
                                        placeholder={__('Select accounts...')}
                                        count={filters.accountIds.length}
                                        testId="filter-accounts"
                                    >
                                        <AccountFacetList
                                            accounts={accounts}
                                            selectedIds={filters.accountIds}
                                            onChange={(accountIds) =>
                                                update({ accountIds })
                                            }
                                        />
                                    </FacetDropdown>
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
                                                update({
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

                                <div className="space-y-2">
                                    <FormLabel>
                                        {__('Counterparties')}
                                    </FormLabel>
                                    <div className="grid grid-cols-2 gap-2 pt-2">
                                        <Input
                                            value={text.values.creditorName}
                                            onChange={(e) =>
                                                text.setValue(
                                                    'creditorName',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder={__('Creditor name')}
                                        />
                                        <Input
                                            value={text.values.debtorName}
                                            onChange={(e) =>
                                                text.setValue(
                                                    'debtorName',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder={__('Debtor name')}
                                        />
                                    </div>
                                </div>
                            </div>
                        </PopoverContent>
                    </Popover>

                    <div className="ml-auto flex items-center gap-2">
                        {enableSavedFilters && (
                            <SavedFilters
                                filters={filters}
                                onLoad={onFiltersChange}
                            />
                        )}

                        {activeFilterCount > 0 && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => onFiltersChange(EMPTY_FILTERS)}
                                className="h-9"
                            >
                                <X className="mr-1 h-4 w-4" />
                                {__('Clear')}
                            </Button>
                        )}
                    </div>
                </div>

                {filters.cashflowBlock ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <CashflowBlockChip
                            block={filters.cashflowBlock}
                            onRemove={() => update({ cashflowBlock: null })}
                        />
                    </div>
                ) : null}

                {actions ? <div className="w-full">{actions}</div> : null}
            </div>
        </div>
    );
}

/**
 * A labelled "N selected" button in the phone's filters panel, opening a
 * facet's searchable list.
 */
function FacetDropdown({
    label,
    placeholder,
    count,
    testId,
    children,
}: {
    label: string;
    placeholder: string;
    count: number;
    testId: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-2">
            <FormLabel>{label}</FormLabel>
            <div className="pt-2">
                <Popover>
                    <PopoverTrigger asChild>
                        <Button
                            variant="outline"
                            role="combobox"
                            className="w-full justify-between"
                            data-testid={testId}
                        >
                            {count > 0 ? (
                                <span className="truncate">
                                    {__(':count selected', { count })}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">
                                    {placeholder}
                                </span>
                            )}
                            <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-full p-0" align="start">
                        {children}
                    </PopoverContent>
                </Popover>
            </div>
        </div>
    );
}
