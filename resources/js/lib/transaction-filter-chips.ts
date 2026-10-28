import { CASHFLOW_BLOCK_LABELS } from '@/lib/cashflow-block';
import { type Account } from '@/types/account';
import { type Category, getCategoryTypeLabel } from '@/types/category';
import { type Label } from '@/types/label';
import { type TransactionFilters } from '@/types/transaction';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';

interface FilterChipSources {
    categories: Pick<Category, 'id' | 'name'>[];
    accounts: Pick<Account, 'id' | 'name'>[];
    labels: Pick<Label, 'id' | 'name'>[];
    locale: string;
}

/**
 * One short, human line per active filter, for a summary such as the export
 * dialog's. Ids are spelled as the names they stand for, and an id that no
 * longer resolves (a deleted category) is left out rather than shown raw.
 */
export function describeActiveFilters(
    filters: TransactionFilters,
    { categories, accounts, labels, locale }: FilterChipSources,
): string[] {
    const date = (value: Date) => formatDate(value, 'MMM d, yyyy', locale);
    const namesOf = (ids: string[], items: { id: string; name: string }[]) =>
        ids.flatMap((id) => items.find((item) => item.id === id)?.name ?? []);

    return [
        filters.searchText ? `“${filters.searchText}”` : null,
        rangeChip(filters.dateFrom, filters.dateTo, date),
        rangeChip(filters.amountMin, filters.amountMax, String),
        ...filters.categoryTypes.map(getCategoryTypeLabel),
        ...namesOf(filters.categoryIds, categories),
        ...namesOf(filters.accountIds, accounts),
        ...namesOf(filters.labelIds, labels),
        filters.creditorName
            ? __('Creditor: :name', { name: filters.creditorName })
            : null,
        filters.debtorName
            ? __('Debtor: :name', { name: filters.debtorName })
            : null,
        filters.aiCategorizedOnly ? __('Only show AI guesses') : null,
        filters.cashflowBlock
            ? __('Block: :name', {
                  name: __(CASHFLOW_BLOCK_LABELS[filters.cashflowBlock]),
              })
            : null,
    ].filter((chip): chip is string => chip !== null);
}

function rangeChip<T>(
    from: T | null,
    to: T | null,
    format: (value: T) => string,
): string | null {
    if (from !== null && to !== null) {
        return `${format(from)} – ${format(to)}`;
    }
    if (from !== null) {
        return __('From :value', { value: format(from) });
    }
    if (to !== null) {
        return __('Up to :value', { value: format(to) });
    }

    return null;
}
