import { destroy } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { showsAiUpsell } from '@/components/transactions/ai-upsell-sample';
import { CategorySelect } from '@/components/transactions/category-select';
import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { billing } from '@/routes/settings';
import { transactionSyncService } from '@/services/transaction-sync';
import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

interface CategoryCellProps {
    transaction: DecryptedTransaction;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    onUpdate: (transaction: DecryptedTransaction) => void;
    onCategorized?: (
        transaction: DecryptedTransaction,
        category: Category,
        source: 'transaction_table',
    ) => void;
    className?: string;
    withoutChevronIcon?: boolean;
    /** AI is currently categorizing this row in the background. */
    isCategorizing?: boolean;
}

export function CategoryCell({
    transaction,
    categories,
    accounts,
    banks,
    onUpdate,
    onCategorized,
    className,
    withoutChevronIcon,
    isCategorizing,
}: CategoryCellProps) {
    const [isUpdating, setIsUpdating] = useState(false);
    const isMobile = useIsMobile();
    const { auth, subscriptionsEnabled, aiCategorizationUpsellRate } =
        usePage<SharedData>().props;

    // Free-plan nudge: AI could categorize this row. Sampled to a configurable
    // share of rows so it stays subtle instead of marking every uncategorized one.
    const showAiUpsell =
        !transaction.category_id &&
        subscriptionsEnabled &&
        !auth.hasProPlan &&
        showsAiUpsell(transaction.id, aiCategorizationUpsellRate);

    async function handleCategoryChange(value: string) {
        const categoryId = value === 'null' ? null : value;

        setIsUpdating(true);
        try {
            const updateData: {
                category_id: string | null;
            } = {
                category_id: categoryId,
            };

            const result = await transactionSyncService.update(
                transaction.id,
                updateData,
            );

            const updatedCategory = categoryId
                ? categories.find((c) => c.id === categoryId) || null
                : null;

            const account = accounts.find(
                (a) => a.id === transaction.account_id,
            );
            const bank = account?.bank?.id
                ? banks.find((b) => b.id === account.bank!.id)
                : undefined;

            const updatedTransaction: DecryptedTransaction = {
                ...transaction,
                category_id: categoryId,
                category: updatedCategory,
                category_source: categoryId ? 'manual' : null,
                ai_confidence: null,
                ai_categorized: false,
                account,
                bank,
            };

            onUpdate(updatedTransaction);

            if (result.learned_rule) {
                // The correction already taught the system a forward rule, so
                // confirm that and offer an instant undo — and skip the
                // "Automatize" prompt, which would only offer to create a rule
                // that now exists.
                const ruleId = result.learned_rule.id;

                toast.success(
                    __(
                        'Learned: similar transactions will be categorized automatically.',
                    ),
                    {
                        closeButton: true,
                        duration: 10000,
                        action: {
                            label: __('Undo'),
                            onClick: () => {
                                router.delete(destroy(ruleId).url, {
                                    preserveScroll: true,
                                    preserveState: true,
                                });
                            },
                        },
                    },
                );
            } else if (updatedCategory) {
                onCategorized?.(
                    updatedTransaction,
                    updatedCategory,
                    'transaction_table',
                );
            }
        } catch (error) {
            console.error('Failed to update category:', error);
        } finally {
            setIsUpdating(false);
        }
    }

    const isAiCategorized = transaction.ai_categorized === true;
    const confidencePercent =
        transaction.ai_confidence != null
            ? Math.round(transaction.ai_confidence * 100)
            : null;

    const aiNote =
        confidencePercent != null
            ? __('Categorized by AI with :confidence% confident', {
                  confidence: confidencePercent,
              })
            : __('Categorized by AI');

    // On mobile there is no hover, so the confidence is shown as a row at the
    // top of the open dropdown instead of in a tooltip.
    const aiHeader =
        isAiCategorized && isMobile ? (
            <div className="flex items-center gap-2 border-b px-3 py-2 text-xs text-muted-foreground">
                <AiSparkleIcon className="h-3.5 w-3.5 shrink-0" />
                <span>{aiNote}</span>
            </div>
        ) : undefined;

    const aiIcon = (
        <span className="inline-flex" aria-label={__('Categorized by AI')}>
            <AiSparkleIcon className="h-3.5 w-3.5" />
        </span>
    );

    const aiUpsell = (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>
                    <button
                        type="button"
                        onClick={(event) => {
                            event.stopPropagation();
                            router.visit(billing.url());
                        }}
                        className="inline-flex"
                        aria-label={__('Let AI categorize your transactions')}
                    >
                        <AiSparkleIcon className="h-3.5 w-3.5 animate-pulse transition-opacity hover:opacity-100" />
                    </button>
                </TooltipTrigger>
                <TooltipContent>
                    {__('Let AI categorize your transactions')}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );

    if (isCategorizing) {
        return (
            <div
                className={cn(
                    'flex w-full animate-pulse items-center gap-2 text-muted-foreground',
                    className,
                )}
            >
                <Spinner className="size-4 shrink-0" />
                <span className="truncate text-sm">{__('Categorizing…')}</span>
            </div>
        );
    }

    return (
        <div className="flex w-full items-center gap-1">
            <div className="min-w-0 flex-1">
                <CategorySelect
                    value={
                        transaction.category_id
                            ? String(transaction.category_id)
                            : 'null'
                    }
                    onValueChange={handleCategoryChange}
                    categories={categories}
                    disabled={isUpdating}
                    placeholder={__('Uncategorized')}
                    triggerClassName={cn(
                        'h-auto w-full border-0 bg-transparent p-0 shadow-none focus:ring-0',
                        className || '',
                    )}
                    showUncategorized={true}
                    withoutChevronIcon={withoutChevronIcon}
                    header={aiHeader}
                    data-testid="row-category-select"
                />
            </div>

            {/* Trailing icon in a fixed-width slot pinned to the column's right
                edge — always reserved (empty when not AI) so the icon lines up
                horizontally on every row. Desktop shows confidence on hover;
                mobile relies on the dropdown header. */}
            <span className="flex w-3.5 shrink-0 items-center justify-center">
                {isAiCategorized ? (
                    isMobile ? (
                        aiIcon
                    ) : (
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    {aiIcon}
                                </TooltipTrigger>
                                <TooltipContent>{aiNote}</TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    )
                ) : showAiUpsell ? (
                    aiUpsell
                ) : null}
            </span>
        </div>
    );
}
