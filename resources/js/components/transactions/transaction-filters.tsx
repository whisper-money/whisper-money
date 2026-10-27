import { __ } from '@/utils/i18n';
import { ListFilter, Search } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { DesktopTransactionFilters } from '@/components/transactions/transaction-filters-desktop';
import { PeriodStepper } from '@/components/transactions/transaction-filters-period';
import {
    TransactionFiltersSheet,
    type TransactionFiltersSheetProps,
} from '@/components/transactions/transaction-filters-sheet';
import { Button } from '@/components/ui/button';
import { useDebouncedTextFilters } from '@/hooks/use-debounced-text-filters';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import { facetChips } from '@/lib/transaction-filter-facets';
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
    /** Above the filters: the page's own toolbar or header. */
    actions?: ReactNode;
    /** At the far end of the desktop toolbar, such as the column picker. */
    trailing?: ReactNode;
    hideAccountFilter?: boolean;
    enableSavedFilters?: boolean;
    /** How many rows a filter set would list, for the phone's filters sheet. */
    countMatching: TransactionFiltersSheetProps['countMatching'];
}

/**
 * The filters above a transactions list: the chips toolbar on desktop; on a
 * phone, the search, "Filters" with the full-screen sheet, and the period.
 */
export function TransactionFilters({
    hideAccountFilter = false,
    enableSavedFilters = false,
    ...props
}: TransactionFiltersProps) {
    const isMobile = useIsMobile();

    return (
        <div
            className={isMobile ? 'flex flex-col gap-3' : 'flex flex-col gap-4'}
        >
            {props.actions}
            {isMobile ? (
                <PhoneTransactionFilters
                    {...props}
                    hideAccountFilter={hideAccountFilter}
                    enableSavedFilters={enableSavedFilters}
                />
            ) : (
                <DesktopTransactionFilters
                    {...props}
                    hideAccountFilter={hideAccountFilter}
                    enableSavedFilters={enableSavedFilters}
                />
            )}
        </div>
    );
}

/**
 * D's phone bar: the search and "Filters" (with how many facets are on) in a
 * row, the period stepper under them. "Filters" opens the full-screen sheet,
 * whose edits wait for its "See N transactions".
 */
function PhoneTransactionFilters({
    filters,
    onFiltersChange,
    categories,
    labels,
    accounts,
    hideAccountFilter,
    enableSavedFilters,
    countMatching,
}: TransactionFiltersProps & {
    hideAccountFilter: boolean;
    enableSavedFilters: boolean;
}) {
    const locale = useLocale();
    const [sheetOpen, setSheetOpen] = useState(false);
    const text = useDebouncedTextFilters(filters, onFiltersChange);

    // The period has the stepper and the search its field: neither counts.
    const activeFacets = facetChips(filters, {
        categories,
        accounts,
        labels,
        locale,
        hideAccountFilter,
    }).length;

    return (
        <div className="flex flex-col gap-1.5" data-test="transaction-filters">
            <div className="flex items-center gap-2">
                <label className="flex h-11 min-w-0 flex-1 items-center gap-2 rounded-[10px] border border-input bg-muted px-3.5 text-muted-foreground focus-within:border-ring focus-within:ring-[3px] focus-within:ring-ring/50 dark:bg-input/30">
                    <Search className="size-[18px] shrink-0" />
                    <input
                        type="search"
                        value={text.values.searchText}
                        onChange={(e) =>
                            text.setValue('searchText', e.target.value)
                        }
                        placeholder={__('Search')}
                        aria-label={__('Search transactions')}
                        className="min-w-0 flex-1 bg-transparent text-[15px] text-foreground outline-none placeholder:text-muted-foreground"
                        data-testid="transaction-search"
                    />
                </label>
                <Button
                    className="h-11 rounded-[10px] px-3.5 text-[15px]"
                    onClick={() => setSheetOpen(true)}
                    data-testid="open-filters-sheet"
                >
                    <ListFilter className="size-[18px]" />
                    {__('Filters')}
                    {activeFacets > 0 && (
                        <span
                            className="rounded-full bg-primary-foreground px-[7px] text-xs font-semibold text-primary"
                            data-testid="filters-badge"
                        >
                            {activeFacets}
                        </span>
                    )}
                </Button>
            </div>

            <div className="-mx-3">
                <PeriodStepper
                    layout="bar"
                    dateFrom={filters.dateFrom}
                    dateTo={filters.dateTo}
                    onChange={(range) =>
                        onFiltersChange({ ...filters, ...range })
                    }
                />
            </div>

            <TransactionFiltersSheet
                open={sheetOpen}
                onOpenChange={setSheetOpen}
                filters={filters}
                onApply={onFiltersChange}
                countMatching={countMatching}
                categories={categories}
                labels={labels}
                accounts={accounts}
                hideAccountFilter={hideAccountFilter}
                enableSavedFilters={enableSavedFilters}
            />
        </div>
    );
}
