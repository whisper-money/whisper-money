import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { RuleEvaluationResult } from '@/lib/rule-engine';
import type { Category } from '@/types/category';
import type { DecryptedTransaction } from '@/types/transaction';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { format, parseISO } from 'date-fns';
import { ArrowRight, Loader2, Sparkles } from 'lucide-react';

export interface CategorizerAutomationRuleMatch {
    transaction: DecryptedTransaction;
    result: RuleEvaluationResult;
}

interface ApplyCategorizerAutomationRulesDialogProps {
    open: boolean;
    matches: CategorizerAutomationRuleMatch[];
    categories: Category[];
    applying: boolean;
    onOpenChange: (open: boolean) => void;
    onApply: () => void;
}

export function ApplyCategorizerAutomationRulesDialog({
    open,
    matches,
    categories,
    applying,
    onOpenChange,
    onApply,
}: ApplyCategorizerAutomationRulesDialogProps) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="overflow-x-hidden sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle>
                        {__('Apply rules to remaining transactions?')}
                    </DialogTitle>
                    <DialogDescription>
                        {__(
                            'Preview the transactions these rules will categorize before applying them.',
                        )}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    <div className="flex items-start gap-3 rounded-md border bg-muted/30 p-4">
                        <Sparkles className="mt-0.5 h-5 w-5 text-primary" />
                        <div className="space-y-1 text-sm">
                            <p className="font-medium">
                                {__(':count transaction(s) will be affected.', {
                                    count: String(matches.length),
                                })}
                            </p>
                            <p className="text-muted-foreground">
                                {__(
                                    'If you skip, rules will still apply automatically to future transactions.',
                                )}
                            </p>
                        </div>
                    </div>

                    <div className="max-h-80 overflow-y-auto rounded-md border">
                        <ul className="divide-y">
                            {matches.map((match) => (
                                <PreviewRow
                                    key={match.transaction.id}
                                    match={match}
                                    categories={categories}
                                />
                            ))}
                        </ul>
                    </div>

                    <div className="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            onClick={() => onOpenChange(false)}
                            disabled={applying}
                        >
                            {__('Skip for now')}
                        </Button>
                        <Button onClick={onApply} disabled={applying}>
                            {applying && (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            )}
                            {__('Apply to :count transaction(s)', {
                                count: String(matches.length),
                            })}
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function PreviewRow({
    match,
    categories,
}: {
    match: CategorizerAutomationRuleMatch;
    categories: Category[];
}) {
    const { transaction, result } = match;
    const date = transaction.transaction_date
        ? format(parseISO(transaction.transaction_date), 'MMM d, yyyy')
        : '';
    const currentCategory = transaction.category?.name ?? __('Uncategorized');
    const nextCategory = result.categoryId
        ? (categories.find((category) => category.id === result.categoryId)
              ?.name ?? result.rule.category?.name)
        : result.rule.category?.name;
    const description =
        transaction.decryptedDescription || transaction.description || '';

    return (
        <li className="flex flex-col gap-1 px-3 py-2 text-sm">
            <div className="flex items-center justify-between gap-3">
                <span className="line-clamp-1 font-medium">{description}</span>
                <span className="shrink-0 tabular-nums">
                    {formatCurrency(
                        transaction.amount,
                        transaction.currency_code,
                    )}
                </span>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                <span>{date}</span>
                {transaction.account?.name && (
                    <span>· {transaction.account.name}</span>
                )}
                <span className="ml-auto inline-flex flex-wrap items-center gap-1">
                    <Badge variant="outline">{currentCategory}</Badge>
                    {nextCategory && nextCategory !== currentCategory && (
                        <>
                            <ArrowRight className="h-3 w-3" />
                            <Badge>{nextCategory}</Badge>
                        </>
                    )}
                </span>
            </div>
        </li>
    );
}
