import { AmountFilterFields } from '@/components/transactions/transaction-filters-amount';
import {
    AccountFacetList,
    CategoryFacetList,
    CategoryTypeFacetList,
    LabelFacetList,
} from '@/components/transactions/transaction-filters-facets';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import { type TextFilterValues } from '@/hooks/use-debounced-text-filters';
import { type AmountDirection } from '@/lib/amount-filter';
import {
    CASHFLOW_BLOCK_LABELS,
    CASHFLOW_BLOCK_TONES,
    CASHFLOW_BLOCKS,
} from '@/lib/cashflow-block';
import {
    amountTypeOf,
    type FilterFacet,
    withAmountType,
} from '@/lib/transaction-filter-facets';
import { cn } from '@/lib/utils';
import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type TransactionFilters } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Check } from 'lucide-react';
import { type ReactNode } from 'react';

export interface FacetEditorProps {
    facet: FilterFacet;
    filters: TransactionFilters;
    onFiltersChange: (filters: TransactionFilters) => void;
    categories: Category[];
    labels: Label[];
    accounts: Account[];
    /** The counterparty fields as typed, applied after a pause. */
    text: {
        values: TextFilterValues;
        setValue: (
            filter: 'creditorName' | 'debtorName',
            value: string,
        ) => void;
    };
}

/** Facets whose editor is a searchable list, drawn edge to edge. */
const LIST_FACETS: FilterFacet[] = [
    'categoryType',
    'category',
    'label',
    'account',
];

/** Whether the facet's editor is a list drawn edge to edge. */
export function isListFacet(facet: FilterFacet): boolean {
    return LIST_FACETS.includes(facet);
}

/** The popover's padding for a facet's editor. */
export function facetEditorClassName(facet: FilterFacet): string {
    return isListFacet(facet) ? 'w-72 p-0' : 'w-72 p-3';
}

/**
 * The fields one facet is edited with, in the popover of its chip or of
 * "+ Filter". Every change applies at once.
 */
export function FacetEditor({
    facet,
    filters,
    onFiltersChange,
    categories,
    labels,
    accounts,
    text,
}: FacetEditorProps) {
    const update = (changes: Partial<TransactionFilters>) =>
        onFiltersChange({ ...filters, ...changes });

    switch (facet) {
        case 'type':
            return (
                <OptionList
                    label={__('Type')}
                    options={(['expense', 'income'] as AmountDirection[]).map(
                        (direction) => ({
                            key: direction,
                            label:
                                direction === 'expense'
                                    ? __('Expenses')
                                    : __('Income'),
                            selected: amountTypeOf(filters) === direction,
                            onSelect: () =>
                                onFiltersChange(
                                    withAmountType(filters, direction),
                                ),
                        }),
                    )}
                />
            );
        case 'amount':
            return (
                <div className="flex flex-col gap-1">
                    <FormLabel>{__('Amount')}</FormLabel>
                    <AmountFilterFields
                        amountMin={filters.amountMin}
                        amountMax={filters.amountMax}
                        onChange={update}
                    />
                </div>
            );
        case 'categoryType':
            return (
                <CategoryTypeFacetList
                    selectedTypes={filters.categoryTypes}
                    onChange={(categoryTypes) => update({ categoryTypes })}
                />
            );
        case 'category':
            return (
                <CategoryFacetList
                    categories={categories}
                    selectedIds={filters.categoryIds}
                    onChange={(categoryIds) => update({ categoryIds })}
                />
            );
        case 'label':
            return (
                <LabelFacetList
                    labels={labels}
                    selectedIds={filters.labelIds}
                    onChange={(labelIds) => update({ labelIds })}
                />
            );
        case 'account':
            return (
                <AccountFacetList
                    accounts={accounts}
                    selectedIds={filters.accountIds}
                    onChange={(accountIds) => update({ accountIds })}
                />
            );
        case 'counterparty':
            return (
                <div className="flex flex-col gap-2">
                    <FormLabel>{__('Counterparties')}</FormLabel>
                    <Input
                        value={text.values.creditorName}
                        onChange={(e) =>
                            text.setValue('creditorName', e.target.value)
                        }
                        placeholder={__('Creditor name')}
                        aria-label={__('Creditor name')}
                        autoFocus
                    />
                    <Input
                        value={text.values.debtorName}
                        onChange={(e) =>
                            text.setValue('debtorName', e.target.value)
                        }
                        placeholder={__('Debtor name')}
                        aria-label={__('Debtor name')}
                    />
                </div>
            );
        case 'ai':
            return (
                <label className="flex cursor-pointer items-center gap-2 text-sm">
                    <Checkbox
                        checked={filters.aiCategorizedOnly}
                        onCheckedChange={(checked) =>
                            update({ aiCategorizedOnly: checked === true })
                        }
                    />
                    <AiSparkleIcon className="h-3.5 w-3.5" />
                    {__('Only show AI guesses')}
                </label>
            );
        case 'cashflowBlock':
            return (
                <OptionList
                    label={__('Cashflow block')}
                    options={CASHFLOW_BLOCKS.map((block) => ({
                        key: block,
                        label: __(CASHFLOW_BLOCK_LABELS[block]),
                        leading: (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'size-2.5 shrink-0 rounded-full',
                                    CASHFLOW_BLOCK_TONES[block],
                                )}
                            />
                        ),
                        selected: filters.cashflowBlock === block,
                        onSelect: () => update({ cashflowBlock: block }),
                    }))}
                />
            );
    }
}

interface Option {
    key: string;
    label: string;
    leading?: ReactNode;
    selected: boolean;
    onSelect: () => void;
}

/** A pick-one list, the current pick ticked. */
function OptionList({ label, options }: { label: string; options: Option[] }) {
    return (
        <div className="flex flex-col gap-1">
            <FormLabel className="px-2 pb-1">{label}</FormLabel>
            {options.map((option) => (
                <button
                    key={option.key}
                    type="button"
                    aria-pressed={option.selected}
                    onClick={option.onSelect}
                    className="flex items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground focus-visible:bg-accent focus-visible:outline-none"
                >
                    {option.leading}
                    <span className="flex-1">{option.label}</span>
                    <Check
                        className={cn(
                            'h-4 w-4',
                            option.selected ? 'opacity-100' : 'opacity-0',
                        )}
                    />
                </button>
            ))}
        </div>
    );
}
