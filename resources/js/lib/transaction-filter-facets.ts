import {
    type AmountDirection,
    fromSignedAmountRange,
    toSignedAmountRange,
    withAmountDirection,
} from '@/lib/amount-filter';
import { CASHFLOW_BLOCK_LABELS } from '@/lib/cashflow-block';
import { type Account } from '@/types/account';
import { type Category, getCategoryTypeLabel } from '@/types/category';
import { type Label } from '@/types/label';
import { type TransactionFilters } from '@/types/transaction';
import { __ } from '@/utils/i18n';

/**
 * A kind of filter the desktop toolbar adds from "+ Filter" and shows as a
 * chip. The period and the search have their own controls, so they are not
 * facets.
 */
export type FilterFacet =
    | 'type'
    | 'amount'
    | 'categoryType'
    | 'category'
    | 'account'
    | 'label'
    | 'counterparty'
    | 'ai'
    | 'cashflowBlock';

export interface FacetChip {
    /** Unique among the chips: a facet can show more than one. */
    id: string;
    facet: FilterFacet;
    /** The facet's name, before its value. */
    name: string;
    value: string;
    /** The filters with this chip's filter taken off. */
    remove: (filters: TransactionFilters) => TransactionFilters;
}

interface FacetChipSources {
    categories: Pick<Category, 'id' | 'name'>[];
    accounts: Pick<Account, 'id' | 'name'>[];
    labels: Pick<Label, 'id' | 'name'>[];
    locale: string;
    hideAccountFilter?: boolean;
}

/** The filters with no amount range: any side, any size. */
export const withoutAmount = (
    filters: TransactionFilters,
): TransactionFilters => ({
    ...filters,
    amountMin: null,
    amountMax: null,
});

/** Names shown in a chip before the rest is summed up as "+N". */
const NAMES_IN_CHIP = 2;

/** "Type" and "Amount" both read the one signed amount range. */
function amountInput(filters: TransactionFilters) {
    return fromSignedAmountRange(
        { amountMin: filters.amountMin, amountMax: filters.amountMax },
        'expense',
    );
}

function hasAmountRange(filters: TransactionFilters): boolean {
    return filters.amountMin !== null || filters.amountMax !== null;
}

/**
 * The amount range narrowed to one side only (every expense or every
 * income), keeping any typed sizes. Zero sits on both sides.
 */
export function withAmountType(
    filters: TransactionFilters,
    direction: AmountDirection,
): TransactionFilters {
    const input = withAmountDirection(amountInput(filters), direction);
    const sizes =
        input.min === null && input.max === null ? { ...input, min: 0 } : input;

    return { ...filters, ...toSignedAmountRange(sizes) };
}

/** The side the amount range sits on, if it is on one. */
export function amountTypeOf(
    filters: TransactionFilters,
): AmountDirection | null {
    return hasAmountRange(filters) ? amountInput(filters).direction : null;
}

/** The sizes typed in the amount range, without the side's zero bound. */
function amountBoundsOf(filters: TransactionFilters): {
    min: number | null;
    max: number | null;
} {
    const input = amountInput(filters);

    return {
        min: input.direction !== null && input.min === 0 ? null : input.min,
        max: input.max,
    };
}

function boundsText(
    min: number | null,
    max: number | null,
    format: (value: number) => string,
): string {
    if (min !== null && max !== null) {
        return `${format(min)} – ${format(max)}`;
    }

    return min !== null ? `≥ ${format(min)}` : `≤ ${format(max ?? 0)}`;
}

function namesText(names: string[]): string {
    if (names.length <= NAMES_IN_CHIP) {
        return names.join(', ');
    }

    return `${names.slice(0, NAMES_IN_CHIP).join(', ')} +${names.length - NAMES_IN_CHIP}`;
}

function namesOf(
    ids: string[],
    items: { id: string; name: string }[],
    extra: Record<string, string> = {},
): string {
    const names = ids.flatMap(
        (id) => extra[id] ?? items.find((item) => item.id === id)?.name ?? [],
    );

    return names.length > 0
        ? namesText(names)
        : __(':count selected', { count: ids.length });
}

/**
 * One chip per active facet, in the order "+ Filter" lists them. The
 * counterparty facet shows one chip per name it holds.
 */
export function facetChips(
    filters: TransactionFilters,
    {
        categories,
        accounts,
        labels,
        locale,
        hideAccountFilter = false,
    }: FacetChipSources,
): FacetChip[] {
    const number = new Intl.NumberFormat(locale, {
        maximumFractionDigits: 2,
    });
    const chips: FacetChip[] = [];
    const type = amountTypeOf(filters);
    const bounds = amountBoundsOf(filters);

    const hasBounds = bounds.min !== null || bounds.max !== null;
    const side = type === 'expense' ? __('Expenses') : __('Income');

    // A side with sizes reads as one amount chip, "Expenses ≥ 50", since
    // the sizes mean nothing without it: taking the side off takes them too.
    if (type !== null && !hasBounds) {
        chips.push({
            id: 'type',
            facet: 'type',
            name: __('Type'),
            value: side,
            remove: withoutAmount,
        });
    }

    if (hasBounds) {
        const range = boundsText(bounds.min, bounds.max, (value) =>
            number.format(value),
        );
        chips.push({
            id: 'amount',
            facet: 'amount',
            name: __('Amount'),
            value: type === null ? range : `${side} ${range}`,
            remove: withoutAmount,
        });
    }

    if (filters.categoryTypes.length > 0) {
        chips.push({
            id: 'categoryType',
            facet: 'categoryType',
            name: __('Category type'),
            value: namesText(filters.categoryTypes.map(getCategoryTypeLabel)),
            remove: (current) => ({ ...current, categoryTypes: [] }),
        });
    }

    if (filters.categoryIds.length > 0) {
        chips.push({
            id: 'category',
            facet: 'category',
            name: __('Category'),
            value: namesOf(filters.categoryIds, categories, {
                uncategorized: __('Uncategorized'),
            }),
            remove: (current) => ({ ...current, categoryIds: [] }),
        });
    }

    if (!hideAccountFilter && filters.accountIds.length > 0) {
        chips.push({
            id: 'account',
            facet: 'account',
            name: __('Account'),
            value: namesOf(filters.accountIds, accounts),
            remove: (current) => ({ ...current, accountIds: [] }),
        });
    }

    if (filters.labelIds.length > 0) {
        chips.push({
            id: 'label',
            facet: 'label',
            name: __('Label'),
            value: namesOf(filters.labelIds, labels),
            remove: (current) => ({ ...current, labelIds: [] }),
        });
    }

    if (filters.creditorName) {
        chips.push({
            id: 'creditor',
            facet: 'counterparty',
            name: __('Creditor'),
            value: filters.creditorName,
            remove: (current) => ({ ...current, creditorName: '' }),
        });
    }

    if (filters.debtorName) {
        chips.push({
            id: 'debtor',
            facet: 'counterparty',
            name: __('Debtor'),
            value: filters.debtorName,
            remove: (current) => ({ ...current, debtorName: '' }),
        });
    }

    if (filters.aiCategorizedOnly) {
        chips.push({
            id: 'ai',
            facet: 'ai',
            name: __('Categorized by'),
            value: __('AI'),
            remove: (current) => ({ ...current, aiCategorizedOnly: false }),
        });
    }

    if (filters.cashflowBlock) {
        chips.push({
            id: 'cashflowBlock',
            facet: 'cashflowBlock',
            name: __('Block'),
            value: __(CASHFLOW_BLOCK_LABELS[filters.cashflowBlock]),
            remove: (current) => ({ ...current, cashflowBlock: null }),
        });
    }

    return chips;
}

/**
 * The filters with every chip taken off. The period and the search stay:
 * they have controls of their own, outside the chips.
 */
export function withoutFacets(filters: TransactionFilters): TransactionFilters {
    return {
        ...filters,
        amountMin: null,
        amountMax: null,
        categoryIds: [],
        categoryTypes: [],
        accountIds: [],
        labelIds: [],
        creditorName: '',
        debtorName: '',
        aiCategorizedOnly: false,
        cashflowBlock: null,
    };
}
