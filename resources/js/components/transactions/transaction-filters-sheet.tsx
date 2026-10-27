import { BankLogo } from '@/components/bank-logo';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { LabelIcon } from '@/components/shared/label-icon';
import {
    SavedFilterDialogs,
    SavedFiltersMenu,
    useSavedFilters,
} from '@/components/transactions/saved-filters';
import { AmountFilterFields } from '@/components/transactions/transaction-filters-amount';
import {
    toggleId,
    UNCATEGORIZED_CATEGORY_ID,
    useSelectedFirstCategoryTree,
} from '@/components/transactions/transaction-filters-facets';
import { PeriodFields } from '@/components/transactions/transaction-filters-period';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/hooks/use-locale';
import { clientFiltersToQueryParams } from '@/hooks/use-server-transaction-source';
import { type AmountDirection } from '@/lib/amount-filter';
import {
    CASHFLOW_BLOCK_LABELS,
    CASHFLOW_BLOCK_TONES,
    CASHFLOW_BLOCKS,
} from '@/lib/cashflow-block';
import {
    categorySelectionState,
    toggleCategorySelection,
} from '@/lib/category-tree';
import {
    amountTypeOf,
    facetChips,
    withAmountType,
    withoutFacets,
} from '@/lib/transaction-filter-facets';
import { periodLabel } from '@/lib/transaction-period';
import { cn } from '@/lib/utils';
import { type Account } from '@/types/account';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { getLabelColorClasses, type Label } from '@/types/label';
import { type TransactionFilters } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import * as Icons from 'lucide-react';
import { ChevronDown, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';

export interface TransactionFiltersSheetProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The filters the list shows now, where the sheet starts from. */
    filters: TransactionFilters;
    onApply: (filters: TransactionFilters) => void;
    /** How many rows a filter set would list, for "See N transactions". */
    countMatching: (
        filters: TransactionFilters,
        signal: AbortSignal,
    ) => Promise<number>;
    categories: Category[];
    labels: Label[];
    accounts: Account[];
    hideAccountFilter: boolean;
    enableSavedFilters: boolean;
}

/** Options a section lists before "View all (N)". */
const OPTIONS_SHOWN = 5;

/** How long the filters rest before the sheet counts what they match. */
const COUNT_DEBOUNCE_MS = 300;

/**
 * The phone's filters, full screen: one row per facet, edited as a draft that
 * only reaches the list when "See N transactions" applies it. ✕ (or the back
 * gesture) drops the draft; "Reset" empties it.
 */
export function TransactionFiltersSheet({
    open,
    onOpenChange,
    ...props
}: TransactionFiltersSheetProps) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side="bottom"
                showCloseButton={false}
                className="inset-0 h-dvh gap-0 border-0 p-0"
                data-testid="transaction-filters-sheet"
            >
                {/* Mounted only while open, so every opening starts a new
                    draft from the applied filters. */}
                <FiltersDraft {...props} onClose={() => onOpenChange(false)} />
            </SheetContent>
        </Sheet>
    );
}

function FiltersDraft({
    filters,
    onApply,
    countMatching,
    categories,
    labels,
    accounts,
    hideAccountFilter,
    enableSavedFilters,
    onClose,
}: Omit<TransactionFiltersSheetProps, 'open' | 'onOpenChange'> & {
    onClose: () => void;
}) {
    const locale = useLocale();
    const [draft, setDraft] = useState(filters);
    const [view, setView] = useState<'facets' | 'period'>('facets');
    const count = useMatchingCount(draft, countMatching);
    const saved = useSavedFilters({
        filters: draft,
        onLoad: setDraft,
        enabled: enableSavedFilters,
    });

    const update = (changes: Partial<TransactionFilters>) =>
        setDraft((current) => ({ ...current, ...changes }));

    function apply() {
        // The search is typed outside the sheet and may have moved since.
        onApply({ ...draft, searchText: filters.searchText });
        onClose();
    }

    const period = periodLabel(draft.dateFrom, draft.dateTo, locale);

    if (view === 'period') {
        return (
            <>
                <SheetHeaderBar
                    leading={
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-11"
                            aria-label={__('Back')}
                            onClick={() => setView('facets')}
                        >
                            <ChevronLeft className="size-5" />
                        </Button>
                    }
                    title={__('Period')}
                />
                {/* The desktop's fields, at 44px for a finger. */}
                <div className="flex-1 overflow-y-auto border-t px-5 py-4 [&_button]:h-11 [&_input]:h-11">
                    <PeriodFields
                        dateFrom={draft.dateFrom}
                        dateTo={draft.dateTo}
                        onChange={update}
                        onClear={() => update({ dateFrom: null, dateTo: null })}
                    />
                </div>
                <SheetFooterBar>
                    <Button
                        className="h-11 w-full"
                        onClick={() => setView('facets')}
                    >
                        {__('Done')}
                    </Button>
                </SheetFooterBar>
            </>
        );
    }

    const chips = facetChips(draft, {
        categories,
        accounts,
        labels,
        locale,
        hideAccountFilter,
    });
    const chipValue = (id: string) =>
        chips.find((chip) => chip.id === id)?.value;

    return (
        <>
            <SheetHeaderBar
                leading={
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-11"
                        aria-label={__('Close')}
                        onClick={onClose}
                    >
                        <X className="size-5" />
                    </Button>
                }
                title={__('Filters')}
                trailing={
                    <Button
                        variant="ghost"
                        className="h-11 text-muted-foreground"
                        onClick={() =>
                            setDraft(
                                withoutFacets({
                                    ...draft,
                                    dateFrom: null,
                                    dateTo: null,
                                }),
                            )
                        }
                    >
                        {__('Reset')}
                    </Button>
                }
            />

            <div className="flex-1 overflow-y-auto border-t">
                <button
                    type="button"
                    className={ROW}
                    onClick={() => setView('period')}
                    data-testid="sheet-period-row"
                >
                    {__('Period')}
                    <RowValue>{period}</RowValue>
                    <ChevronRight className="size-4 shrink-0" />
                </button>

                <SheetRow label={__('Type')} className="gap-4">
                    <TypeSegments draft={draft} onChange={setDraft} />
                </SheetRow>

                <SheetSection
                    label={__('Categories')}
                    value={selectionText(draft.categoryIds.length)}
                    testId="sheet-categories"
                >
                    <CategoryOptions
                        categories={categories}
                        selectedIds={draft.categoryIds}
                        onChange={(categoryIds) => update({ categoryIds })}
                    />
                </SheetSection>

                <SheetSection
                    label={__('Amount')}
                    value={chipValue('amount') ?? __('Any')}
                    testId="sheet-amount"
                >
                    <div className="px-5 pb-4">
                        <AmountFilterFields
                            amountMin={draft.amountMin}
                            amountMax={draft.amountMax}
                            onChange={update}
                            showDirection={false}
                        />
                    </div>
                </SheetSection>

                {!hideAccountFilter && (
                    <SheetSection
                        label={__('Accounts')}
                        value={selectionText(draft.accountIds.length)}
                        testId="sheet-accounts"
                    >
                        <CheckOptions
                            options={accounts.map((account) => ({
                                id: account.id,
                                label: account.name,
                                checked: draft.accountIds.includes(account.id),
                                leading: (
                                    <BankLogo
                                        src={account.bank?.logo}
                                        name={account.bank?.name}
                                        fallback="icon"
                                        className="size-7 shrink-0 rounded-full"
                                    />
                                ),
                            }))}
                            onToggle={(id) =>
                                update({
                                    accountIds: toggleId(draft.accountIds, id),
                                })
                            }
                        />
                    </SheetSection>
                )}

                <SheetSection
                    label={__('Labels')}
                    value={selectionText(draft.labelIds.length)}
                    testId="sheet-labels"
                >
                    <CheckOptions
                        options={labels.map((label) => {
                            const colors = getLabelColorClasses(label.color);
                            return {
                                id: label.id,
                                label: label.name,
                                checked: draft.labelIds.includes(label.id),
                                leading: (
                                    <OptionCircle className={colors.bg}>
                                        <LabelIcon
                                            label={label}
                                            className={cn(
                                                'size-3.5',
                                                colors.text,
                                            )}
                                        />
                                    </OptionCircle>
                                ),
                            };
                        })}
                        onToggle={(id) =>
                            update({ labelIds: toggleId(draft.labelIds, id) })
                        }
                    />
                </SheetSection>

                <SheetSection
                    label={__('Counterparty')}
                    value={
                        [draft.creditorName, draft.debtorName]
                            .filter(Boolean)
                            .join(', ') || __('Any')
                    }
                    testId="sheet-counterparty"
                >
                    <div className="flex flex-col gap-2 px-5 pb-4">
                        <Input
                            className="h-11"
                            value={draft.creditorName}
                            onChange={(e) =>
                                update({ creditorName: e.target.value })
                            }
                            placeholder={__('Creditor name')}
                            aria-label={__('Creditor name')}
                        />
                        <Input
                            className="h-11"
                            value={draft.debtorName}
                            onChange={(e) =>
                                update({ debtorName: e.target.value })
                            }
                            placeholder={__('Debtor name')}
                            aria-label={__('Debtor name')}
                        />
                    </div>
                </SheetSection>

                {/* A block only narrows a whole date range. */}
                {draft.dateFrom && draft.dateTo ? (
                    <SheetSection
                        label={__('Cashflow block')}
                        value={chipValue('cashflowBlock') ?? __('Any')}
                        testId="sheet-cashflow-block"
                    >
                        <CheckOptions
                            options={CASHFLOW_BLOCKS.map((block) => ({
                                id: block,
                                label: __(CASHFLOW_BLOCK_LABELS[block]),
                                checked: draft.cashflowBlock === block,
                                leading: (
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'size-2.5 shrink-0 rounded-full',
                                            CASHFLOW_BLOCK_TONES[block],
                                        )}
                                    />
                                ),
                            }))}
                            onToggle={(block) =>
                                update({
                                    cashflowBlock:
                                        draft.cashflowBlock === block
                                            ? null
                                            : (block as TransactionFilters['cashflowBlock']),
                                })
                            }
                        />
                    </SheetSection>
                ) : null}

                <SheetRow label={__('AI guesses only')}>
                    <AiSparkleIcon className="size-4 shrink-0" />
                    <SwitchButton
                        label={__('AI guesses only')}
                        checked={draft.aiCategorizedOnly}
                        onChange={(aiCategorizedOnly) =>
                            update({ aiCategorizedOnly })
                        }
                    />
                </SheetRow>

                {enableSavedFilters && (
                    <SheetRow label={__('Saved views')}>
                        <div className="ml-auto">
                            <SavedFiltersMenu saved={saved} />
                        </div>
                    </SheetRow>
                )}
            </div>

            <SheetFooterBar>
                <Button
                    className="h-11 w-full"
                    onClick={apply}
                    data-testid="apply-filters"
                >
                    {count === null
                        ? __('See transactions')
                        : count === 1
                          ? __('See 1 transaction')
                          : __('See :count transactions', {
                                count: new Intl.NumberFormat(locale).format(
                                    count,
                                ),
                            })}
                </Button>
            </SheetFooterBar>

            {enableSavedFilters && <SavedFilterDialogs saved={saved} />}
        </>
    );
}

/**
 * How many rows the draft matches, counted a moment after it stops changing.
 * Null while that count is on its way.
 */
function useMatchingCount(
    draft: TransactionFilters,
    countMatching: TransactionFiltersSheetProps['countMatching'],
): number | null {
    const key = JSON.stringify(clientFiltersToQueryParams(draft));
    const [counted, setCounted] = useState<{
        key: string;
        count: number;
    } | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        const timer = setTimeout(() => {
            countMatching(draft, controller.signal)
                .then((count) => setCounted({ key, count }))
                // A newer draft replaced it, or the count failed: the button
                // keeps saying "See transactions".
                .catch(() => {});
        }, COUNT_DEBOUNCE_MS);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
        // The key stands for the draft: an edit that changes nothing the
        // server reads does not count again.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key, countMatching]);

    return counted?.key === key ? counted.count : null;
}

/** "N selected", or "Any" while the facet narrows nothing. */
function selectionText(count: number): string {
    return count > 0 ? __(':count selected', { count }) : __('Any');
}

/** A sheet row: 56px, the label first. */
const ROW =
    'flex min-h-14 w-full items-center gap-3 border-b px-5 text-left text-[15px] text-foreground';

function SheetHeaderBar({
    leading,
    title,
    trailing,
}: {
    leading: ReactNode;
    title: string;
    trailing?: ReactNode;
}) {
    return (
        <div className="flex items-center px-2 pt-[max(1rem,env(safe-area-inset-top))] pb-2">
            <div className="flex w-[104px]">{leading}</div>
            <SheetTitle className="flex-1 text-center text-[17px]">
                {title}
            </SheetTitle>
            <SheetDescription className="sr-only">
                {__('Pick the filters, then apply them to the list.')}
            </SheetDescription>
            <div className="flex w-[104px] justify-end">{trailing}</div>
        </div>
    );
}

function SheetFooterBar({ children }: { children: ReactNode }) {
    return (
        <div className="border-t bg-background px-4 pt-3 pb-[max(1.75rem,env(safe-area-inset-bottom))]">
            {children}
        </div>
    );
}

function SheetRow({
    label,
    className,
    children,
}: {
    label: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn(ROW, className)}>
            <span className="shrink-0">{label}</span>
            {children}
        </div>
    );
}

function RowValue({ children }: { children: ReactNode }) {
    return (
        <span className="ml-auto max-w-[170px] truncate text-sm text-muted-foreground">
            {children}
        </span>
    );
}

/** A facet folded into one row, opening below it. */
function SheetSection({
    label,
    value,
    testId,
    children,
}: {
    label: string;
    value: string;
    testId: string;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Collapsible
            open={open}
            onOpenChange={setOpen}
            className="border-b"
            data-testid={testId}
        >
            <CollapsibleTrigger
                className={cn(ROW, 'border-b-0', open && 'font-semibold')}
            >
                {label}
                <RowValue>
                    <span className="font-normal">{value}</span>
                </RowValue>
                <ChevronDown
                    className={cn(
                        'size-4 shrink-0 transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </CollapsibleTrigger>
            <CollapsibleContent>{children}</CollapsibleContent>
        </Collapsible>
    );
}

const TYPE_SEGMENTS: { value: AmountDirection | null; label: () => string }[] =
    [
        { value: null, label: () => __('All') },
        { value: 'expense', label: () => __('Expenses') },
        { value: 'income', label: () => __('Income') },
    ];

/** All / Expenses / Income: the side of the amount range. */
function TypeSegments({
    draft,
    onChange,
}: {
    draft: TransactionFilters;
    onChange: (filters: TransactionFilters) => void;
}) {
    const type = amountTypeOf(draft);

    return (
        <div
            role="group"
            aria-label={__('Type')}
            className="flex flex-1 gap-0.5 rounded-[10px] bg-muted p-[3px]"
        >
            {TYPE_SEGMENTS.map(({ value, label }) => (
                <button
                    key={value ?? 'all'}
                    type="button"
                    aria-pressed={type === value}
                    onClick={() =>
                        onChange(
                            value === null
                                ? { ...draft, amountMin: null, amountMax: null }
                                : withAmountType(draft, value),
                        )
                    }
                    className={cn(
                        'h-9 flex-1 rounded-lg text-sm text-muted-foreground transition-colors',
                        type === value &&
                            'bg-background font-medium text-foreground shadow-xs dark:bg-input/50',
                    )}
                >
                    {label()}
                </button>
            ))}
        </div>
    );
}

interface CheckOption {
    id: string;
    label: string;
    checked: boolean | 'indeterminate';
    leading?: ReactNode;
    /** Nesting, for subcategories. */
    depth?: number;
}

/**
 * A facet's values as 52px checkbox rows, the first few at once and the rest
 * behind "View all (N)".
 */
function CheckOptions({
    options,
    onToggle,
}: {
    options: CheckOption[];
    onToggle: (id: string) => void;
}) {
    const [showAll, setShowAll] = useState(false);
    const shown = showAll ? options : options.slice(0, OPTIONS_SHOWN);

    if (options.length === 0) {
        return (
            <p className="px-6 pb-4 text-sm text-muted-foreground">
                {__('Nothing to pick yet.')}
            </p>
        );
    }

    return (
        <div className="flex flex-col pb-2">
            {shown.map((option) => (
                <label
                    key={option.id}
                    className="flex h-[52px] cursor-pointer items-center gap-3 pr-5 text-[15px]"
                    style={{ paddingLeft: `${1.5 + (option.depth ?? 0)}rem` }}
                >
                    <Checkbox
                        className="size-5 rounded-md"
                        checked={option.checked}
                        onCheckedChange={() => onToggle(option.id)}
                    />
                    {option.leading}
                    <span className="min-w-0 truncate">{option.label}</span>
                </label>
            ))}
            {options.length > OPTIONS_SHOWN && (
                <button
                    type="button"
                    className="h-11 self-start pr-5 pl-6 text-sm underline-offset-4 hover:underline"
                    onClick={() => setShowAll(!showAll)}
                >
                    {showAll
                        ? __('Show less')
                        : __('View all (:count)', { count: options.length })}
                </button>
            )}
        </div>
    );
}

function OptionCircle({
    className,
    children,
}: {
    className?: string;
    children?: ReactNode;
}) {
    return (
        <span
            className={cn(
                'flex size-7 shrink-0 items-center justify-center rounded-full',
                className,
            )}
        >
            {children}
        </span>
    );
}

/**
 * "Uncategorized", then the category tree in its colours, the branches picked
 * when the sheet opened first so they show before "View all".
 */
function CategoryOptions({
    categories,
    selectedIds,
    onChange,
}: {
    categories: Category[];
    selectedIds: string[];
    onChange: (categoryIds: string[]) => void;
}) {
    const tree = useSelectedFirstCategoryTree(categories, selectedIds);
    const selectedSet = useMemo(() => new Set(selectedIds), [selectedIds]);

    const options: CheckOption[] = [
        {
            id: UNCATEGORIZED_CATEGORY_ID,
            label: __('Uncategorized'),
            checked: selectedSet.has(UNCATEGORIZED_CATEGORY_ID),
            leading: (
                <OptionCircle className="bg-zinc-100 dark:bg-zinc-800">
                    <Icons.HelpCircle className="size-3.5 text-zinc-500" />
                </OptionCircle>
            ),
        },
        ...tree.map((category) => {
            const state = categorySelectionState(
                category.id,
                selectedSet,
                categories,
            );
            return {
                id: category.id,
                label: category.name,
                depth: category.depth,
                checked:
                    state === 'indeterminate'
                        ? ('indeterminate' as const)
                        : state === 'checked',
                leading: (
                    <OptionCircle
                        className={getCategoryColorClasses(category.color).bg}
                    >
                        <CategoryIcon category={category} />
                    </OptionCircle>
                ),
            };
        }),
    ];

    return (
        <CheckOptions
            options={options}
            onToggle={(id) =>
                onChange(toggleCategorySelection(selectedIds, id, categories))
            }
        />
    );
}

/** An on/off switch, 44px wide. */
function SwitchButton({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative ml-auto h-[26px] w-11 shrink-0 rounded-full transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                checked ? 'bg-primary' : 'bg-neutral-300 dark:bg-neutral-600',
            )}
        >
            <span
                className={cn(
                    'absolute top-[3px] left-[3px] size-5 rounded-full bg-background shadow-xs transition-transform',
                    checked && 'translate-x-[18px]',
                )}
            />
        </button>
    );
}
