import {
    ReviewSectionTitle,
    ReviewStepLayout,
} from '@/components/forecast/forecast-review-layout';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState, EmptyStateDescription } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import {
    canMerge,
    draftAmount,
    mergeDrafts,
    type ReviewLineDraft,
    type ReviewSection,
    sectionOf,
    separateDraft,
    suggestionDetail,
} from '@/lib/forecast/review';
import { cn } from '@/lib/utils';
import {
    type ForecastDailySpend,
    type ForecastReview,
    type ForecastSuggestion,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { MoreHorizontal } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';

const OUTSIDE = 'outside';

interface RecurringStepProps {
    review: ForecastReview;
    drafts: ReviewLineDraft[];
    onDraftsChange: (drafts: ReviewLineDraft[]) => void;
    onSkip: () => void;
    onNext: () => void;
}

function sectionTitle(section: ReviewSection): string {
    switch (section) {
        case 'income':
            return __('Income');
        case 'expenses':
            return __('Expenses');
        case 'transfers':
            return __('Transfers between your accounts');
    }
}

/**
 * Step 1 of the review: the movements that repeat, by income, expenses and
 * transfers, each with a tick (on for the ones the detection is sure of), its
 * schedule, how sure it is and its amount. A row can be renamed in place,
 * combined with another of the same account, or split back.
 */
export function ForecastReviewRecurringStep({
    review,
    drafts,
    onDraftsChange,
    onSkip,
    onNext,
}: RecurringStepProps) {
    const suggestions = useMemo(
        () =>
            Object.fromEntries(
                review.recurring.map((suggestion) => [
                    suggestion.key,
                    suggestion,
                ]),
            ),
        [review.recurring],
    );
    const sections = (['income', 'expenses', 'transfers'] as const)
        .map((section) => ({
            section,
            drafts: drafts.filter(
                (draft) => sectionOf(suggestions[draft.id]) === section,
            ),
        }))
        .filter((group) => group.drafts.length > 0);
    const total = review.recurring.length;
    const selected = drafts
        .filter((draft) => draft.selected)
        .reduce((count, draft) => count + draft.keys.length, 0);
    const update = (id: string, change: Partial<ReviewLineDraft>) =>
        onDraftsChange(
            drafts.map((draft) =>
                draft.id === id ? { ...draft, ...change } : draft,
            ),
        );

    return (
        <ReviewStepLayout
            eyebrow={__('Step 1 of 2 · Forecast')}
            title={
                total === 0
                    ? __('We found no movements that repeat')
                    : total === 1
                      ? __('We found 1 movement that repeats')
                      : __('We found :count movements that repeat', {
                            count: total,
                        })
            }
            description={__(
                'Review the ones from your last 12 months. The ones you leave ticked will be used to forecast your balance; you can change them whenever you want.',
            )}
            footer={
                <>
                    <span
                        className="text-sm text-muted-foreground"
                        data-test="forecast-review-selected"
                    >
                        {__(':selected of :total selected', {
                            selected,
                            total,
                        })}
                    </span>
                    <div className="flex gap-2">
                        <Button variant="outline" onClick={onSkip}>
                            {__('Not now')}
                        </Button>
                        <Button
                            onClick={onNext}
                            data-test="forecast-review-next"
                        >
                            {__('Next: yearly payments')}
                        </Button>
                    </div>
                </>
            }
        >
            {total === 0 && (
                <EmptyState size="inline">
                    <EmptyStateDescription>
                        {__(
                            'Nothing repeats often enough in your movements yet. Add your yearly payments in the next step, and we will suggest more as your history grows.',
                        )}
                    </EmptyStateDescription>
                </EmptyState>
            )}
            {sections.map((group) => (
                <section
                    key={group.section}
                    className="flex flex-col gap-1"
                    data-test={`forecast-review-section-${group.section}`}
                >
                    <ReviewSectionTitle>
                        {sectionTitle(group.section)}
                    </ReviewSectionTitle>
                    {group.drafts.map((draft) => (
                        <SuggestionRow
                            key={draft.id}
                            draft={draft}
                            suggestions={suggestions}
                            mergeTargets={group.drafts.filter((other) =>
                                canMerge(
                                    suggestions[draft.id],
                                    suggestions[other.id],
                                ),
                            )}
                            accounts={review.accounts}
                            onChange={(change) => update(draft.id, change)}
                            onMerge={(sourceId) =>
                                onDraftsChange(
                                    mergeDrafts(drafts, draft.id, sourceId),
                                )
                            }
                            onSeparate={() =>
                                onDraftsChange(
                                    separateDraft(
                                        drafts,
                                        draft.id,
                                        suggestions,
                                    ),
                                )
                            }
                        />
                    ))}
                </section>
            ))}
            {review.dailySpend && (
                <DailySpendBox dailySpend={review.dailySpend} />
            )}
        </ReviewStepLayout>
    );
}

interface SuggestionRowProps {
    draft: ReviewLineDraft;
    suggestions: Record<string, ForecastSuggestion>;
    mergeTargets: ReviewLineDraft[];
    accounts: ForecastReview['accounts'];
    onChange: (change: Partial<ReviewLineDraft>) => void;
    onMerge: (sourceId: string) => void;
    onSeparate: () => void;
}

function SuggestionRow({
    draft,
    suggestions,
    mergeTargets,
    accounts,
    onChange,
    onMerge,
    onSeparate,
}: SuggestionRowProps) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const [renaming, setRenaming] = useState(false);
    const suggestion = suggestions[draft.id];
    const amount = draftAmount(draft, suggestions);
    const isTransfer = suggestion.kind === 'transfer';
    const checkboxId = `forecast-review-${draft.id}`;
    const detail =
        draft.keys.length > 1
            ? __('Combines :names', {
                  names: draft.keys
                      .map((key) => suggestions[key].name)
                      .join(', '),
              })
            : suggestionDetail(suggestion, locale, (value) =>
                  formatAmount(value, suggestion.currencyCode, {
                      fractionDigits: 0,
                  }),
              );

    return (
        <Card size="flush" data-test="forecast-review-row">
            <div className="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-2 py-2.5 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto_auto]">
                <Checkbox
                    id={checkboxId}
                    checked={draft.selected}
                    onCheckedChange={(checked) =>
                        onChange({ selected: checked === true })
                    }
                    aria-label={draft.name}
                />
                <div className="flex min-w-0 flex-col gap-0.5">
                    {renaming ? (
                        <Input
                            ref={focusWithTextSelected}
                            defaultValue={draft.name}
                            aria-label={__('Name')}
                            className="h-8"
                            maxLength={255}
                            onBlur={(event) => {
                                onChange({
                                    name:
                                        event.target.value.trim() || draft.name,
                                });
                                setRenaming(false);
                            }}
                            onKeyDown={(event) => {
                                if (event.key === 'Enter') {
                                    event.currentTarget.blur();
                                }

                                if (event.key === 'Escape') {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    setRenaming(false);
                                }
                            }}
                        />
                    ) : (
                        <button
                            type="button"
                            className="truncate text-left text-sm font-medium hover:underline"
                            onClick={() => setRenaming(true)}
                            title={__('Rename')}
                        >
                            {draft.name}
                        </button>
                    )}
                    <label
                        htmlFor={checkboxId}
                        className="text-xs text-muted-foreground"
                    >
                        {detail}
                    </label>
                    {isTransfer && suggestion.destinationUnknown && (
                        <Select
                            value={draft.destinationAccountId ?? OUTSIDE}
                            onValueChange={(value) =>
                                onChange({
                                    destinationAccountId:
                                        value === OUTSIDE ? null : value,
                                })
                            }
                        >
                            <SelectTrigger
                                className="mt-1 h-8 w-full max-w-64 text-xs"
                                aria-label={__('Where it goes')}
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={OUTSIDE}>
                                    {__(
                                        'Outside Whisper Money (counts as spending)',
                                    )}
                                </SelectItem>
                                {accounts
                                    .filter(
                                        (account) =>
                                            account.id !== suggestion.accountId,
                                    )
                                    .map((account) => (
                                        <SelectItem
                                            key={account.id}
                                            value={account.id}
                                        >
                                            {account.name}
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>
                <Badge
                    variant={
                        suggestion.confidence === 'high' ? 'success' : 'warning'
                    }
                    className="col-start-2 row-start-2 rounded-full sm:col-start-auto sm:row-start-auto"
                >
                    {suggestion.confidence === 'high'
                        ? __('Sure')
                        : __('Review')}
                </Badge>
                <AmountDisplay
                    amountInCents={isTransfer ? Math.abs(amount) : amount}
                    currencyCode={suggestion.currencyCode}
                    showSign={!isTransfer}
                    maximumFractionDigits={0}
                    minimumFractionDigits={0}
                    className={cn(
                        'min-w-20 text-right text-sm font-medium tabular-nums',
                        !isTransfer && amount > 0 && 'text-success',
                    )}
                />
                <RowMenu
                    draft={draft}
                    mergeTargets={mergeTargets}
                    onRename={() => setRenaming(true)}
                    onMerge={onMerge}
                    onSeparate={onSeparate}
                />
            </div>
        </Card>
    );
}

/**
 * Focuses the name field as it opens, with its whole text selected, so typing
 * replaces the name. `setSelectionRange` rather than `select()`, which iOS
 * Safari ignores.
 */
function focusWithTextSelected(input: HTMLInputElement | null): void {
    if (input) {
        input.focus();
        input.setSelectionRange(0, input.value.length);
    }
}

function RowMenu({
    draft,
    mergeTargets,
    onRename,
    onMerge,
    onSeparate,
}: {
    draft: ReviewLineDraft;
    mergeTargets: ReviewLineDraft[];
    onRename: () => void;
    onMerge: (sourceId: string) => void;
    onSeparate: () => void;
}) {
    // The name field opens once the menu has closed: while the menu is open
    // its focus trap would steal the focus back, and closing it would hand
    // the focus to its trigger instead of the field.
    const renamePicked = useRef(false);

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="col-start-3 row-start-2 size-8 justify-self-end sm:col-start-auto sm:row-start-auto"
                    aria-label={__('More options for :name', {
                        name: draft.name,
                    })}
                >
                    <MoreHorizontal className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                onCloseAutoFocus={(event) => {
                    if (renamePicked.current) {
                        event.preventDefault();
                        renamePicked.current = false;
                        onRename();
                    }
                }}
            >
                <DropdownMenuItem
                    onSelect={() => {
                        renamePicked.current = true;
                    }}
                >
                    {__('Rename')}
                </DropdownMenuItem>
                {mergeTargets.length > 0 && (
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            {__('Combine with')}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            {mergeTargets.map((target) => (
                                <DropdownMenuItem
                                    key={target.id}
                                    onSelect={() => onMerge(target.id)}
                                >
                                    {target.name}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                )}
                {draft.keys.length > 1 && (
                    <DropdownMenuItem onSelect={onSeparate}>
                        {__('Separate again (:count)', {
                            count: draft.keys.length,
                        })}
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/**
 * "Day-to-day spending": what the forecast estimates beyond the concrete
 * lines, from the budgets that fit a category, else the median of the last
 * three months.
 */
function DailySpendBox({ dailySpend }: { dailySpend: ForecastDailySpend }) {
    const { formatAmount } = useFormatAmount();
    const format = (amount: number) =>
        formatAmount(amount, dailySpend.currencyCode, { fractionDigits: 0 });

    return (
        <section
            className="flex flex-col gap-1"
            data-test="forecast-review-daily"
        >
            <ReviewSectionTitle>{__('Day-to-day spending')}</ReviewSectionTitle>
            <div className="flex flex-col gap-1.5 rounded-lg border bg-muted/50 p-3">
                <span className="text-sm">
                    {dailySpend.budgets.length > 0
                        ? __('We will use your budgets: :budgets.', {
                              budgets: dailySpend.budgets
                                  .map(
                                      (budget) =>
                                          `${budget.name} ${format(budget.amount)}`,
                                  )
                                  .join(' · '),
                          })
                        : __(
                              'You have no monthly budget per category, so we estimate everything from your recent months.',
                          )}
                </span>
                <span className="text-[13px] text-muted-foreground">
                    {dailySpend.median
                        ? __(
                              'What has no budget is estimated with the median of the last 3 months (:name ≈ :amount).',
                              {
                                  name: dailySpend.median.name,
                                  amount: format(dailySpend.median.amount),
                              },
                          )
                        : __(
                              'What has no budget is estimated with the median of the last 3 months.',
                          )}
                </span>
                {dailySpend.unusableBudgets.length > 0 && (
                    <span className="text-[13px] text-muted-foreground">
                        {__(
                            'We cannot use :budgets: they are not monthly, follow labels or mix categories, so their categories use the median.',
                            { budgets: dailySpend.unusableBudgets.join(', ') },
                        )}
                    </span>
                )}
            </div>
        </section>
    );
}
